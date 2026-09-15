<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DevolucionesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function producto(string $codigo = 'P-0004'): Producto
    {
        // P-0004 se vende por unidad (UND): evita fracciones en las cuentas.
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function turno(?Usuario $usuario = null, float $inicial = 200): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), $inicial);
    }

    /** Venta de dos líneas: 3 de un producto y 2 de otro. */
    private function ventaConDosLineas(SesionCaja $sesion): Venta
    {
        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [
                ['producto_id' => $this->producto('P-0004')->id, 'cantidad' => 3, 'precio_unitario' => 3.54],
                ['producto_id' => $this->producto('P-0009')->id, 'cantidad' => 2, 'precio_unitario' => 2.48],
            ],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- permisos

    /** Solo el rol con `devoluciones.registrar` puede registrarlas. */
    public function test_el_cajero_no_registra_devoluciones(): void
    {
        $sesion = $this->turno($this->cajero());
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->cajero())
            ->get("/ventas/{$venta->id}/devolver")
            ->assertForbidden();

        $this->actingAs($this->cajero())
            ->post("/ventas/{$venta->id}/devolver", [
                'motivo' => 'Producto en mal estado',
                'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_el_administrador_entra_a_devoluciones(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->admin())->get('/devoluciones')->assertOk();
        $this->actingAs($this->admin())->get("/ventas/{$venta->id}/devolver")->assertOk();
    }

    // ------------------------------------------------------------ parciales

    public function test_una_devolucion_parcial_reingresa_stock_y_deja_la_venta_parcial(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0004');
        $stockAntes = (float) $producto->fresh()->stock_actual;

        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->firstWhere('producto_id', $producto->id);

        // La venta ya descontó 3.
        $this->assertSame($stockAntes - 3, (float) $producto->fresh()->stock_actual);

        $devolucion = Devoluciones::registrar(
            venta: $venta,
            usuario: $this->admin(),
            sesion: $sesion,
            lineas: [['venta_detalle_id' => $linea->id, 'cantidad' => 2]],
            motivo: 'Dos unidades llegaron abolladas',
        );

        // Vuelven 2 al estante.
        $this->assertSame($stockAntes - 1, (float) $producto->fresh()->stock_actual);

        $this->assertSame('PARCIAL', $devolucion->tipo);
        // 2 × 3.54 = 7.08 de base, más 0.92 de impuesto
        $this->assertSame('8.00', $devolucion->total);
        $this->assertSame('DEVUELTA_PARCIAL', $venta->fresh()->estado);
        $this->assertSame('8.00', $venta->fresh()->total_devuelto);
        $this->assertSame('2.000', $linea->fresh()->cantidad_devuelta);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'devolucion_id' => $devolucion->id,
            'origen' => 'DEVOLUCION',
            'tipo' => 'ENTRADA',
        ]);
    }

    /**
     * `venta_detalle.precio_unitario` es el de catálogo: el descuento de
     * cabecera solo vive en `ventas.descuento`, prorrateado sobre el total
     * (igual que `sp_recalcular_venta`). Sin prorratearlo también en la
     * devolución, devolver una venta con descuento reembolsaba más de lo que
     * el cliente pagó —y restaba de más del cajón al cerrar.
     */
    public function test_devolver_una_venta_con_descuento_reembolsa_lo_que_se_pago(): void
    {
        $sesion = $this->turno();

        // 2 × 10 = 20 de base, 2 de descuento (10%): factor 0.9.
        // Impuesto bruto 20 × 0.13 = 2.60, neto 2.60 × 0.9 = 2.34.
        // Total pagado: 20 − 2 + 2.34 = 20.34.
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2, 'precio_unitario' => 10]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            descuento: 2,
        );

        $this->assertSame('20.34', $venta->total);

        $devolucion = Devoluciones::registrar(
            venta: $venta,
            usuario: $this->admin(),
            sesion: $sesion,
            lineas: [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]],
            motivo: 'El cliente se arrepintió de toda la compra',
        );

        // Devolución total: se reembolsa exactamente lo que se cobró, ni un
        // céntimo más —el precio de catálogo sin descontar hubiera dado 22.60.
        $this->assertSame($venta->fresh()->total, $devolucion->total);
        $this->assertSame('20.34', $devolucion->total);
        $this->assertSame('9.00', (string) $devolucion->detalle->first()->precio_unitario);
    }

    /**
     * El formulario de devolución tiene que mostrarle al cajero el mismo
     * precio por unidad que `Devoluciones::registrar()` va a guardar — si
     * muestra el de catálogo sin prorratear el descuento de cabecera, la
     * pantalla promete devolver más dinero del que el sistema realmente
     * descuenta del cajón.
     */
    public function test_el_formulario_muestra_el_precio_ya_prorrateado_por_el_descuento(): void
    {
        $sesion = $this->turno();

        // Mismos datos que test_devolver_una_venta_con_descuento_reembolsa_lo_que_se_pago:
        // precio de catálogo 10, descuento de cabecera 2 sobre 20 de base -> factor 0.9 -> precio neto 9.
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2, 'precio_unitario' => 10]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            descuento: 2,
        );

        $respuesta = $this->actingAs($this->admin())->get("/ventas/{$venta->id}/devolver")->assertOk();

        // `@js()` envuelve el JSON en `JSON.parse('...')` con los caracteres
        // especiales escapados como \uXXXX (JSON_HEX_*), para poder ir dentro
        // de un atributo HTML entre comillas dobles sin romperlo. Se revierte
        // ese escape para volver a tener el JSON original y decodificarlo.
        // El primer argumento son las opciones (proporción en efectivo); el
        // segundo, las líneas.
        preg_match("/devolucion\(JSON\.parse\('.+?'\), JSON\.parse\('(.+?)'\)\)/s", $respuesta->getContent(), $m);
        $this->assertNotEmpty($m, 'no se encontró el x-data del formulario de devolución');

        $lineas = json_decode(json_decode('"'.$m[1].'"'), true);
        $linea = collect($lineas)->firstWhere('id', $venta->detalle->first()->id);

        $this->assertEqualsWithDelta(9.0, $linea['precio'], 0.001, 'el formulario debería mostrar el precio neto de descuento (9), no el de catálogo (10)');
    }

    /** Devolver todas las líneas deja la venta en DEVUELTA y la marca TOTAL. */
    public function test_devolver_todo_deja_la_venta_devuelta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $devolucion = Devoluciones::registrar(
            venta: $venta,
            usuario: $this->admin(),
            sesion: $sesion,
            lineas: $venta->detalle->map(fn ($l) => [
                'venta_detalle_id' => $l->id,
                'cantidad' => (float) $l->cantidad,
            ])->all(),
            motivo: 'El cliente devolvió toda la compra',
        );

        $this->assertSame('TOTAL', $devolucion->tipo);
        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
        // Devolver todo reintegra exactamente lo que la venta cobro.
        $this->assertSame($venta->fresh()->total, $devolucion->total);
    }

    /** Se puede devolver en varias veces hasta agotar la venta. */
    public function test_dos_devoluciones_sucesivas_agotan_la_venta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        [$primera, $segunda] = [$venta->detalle[0], $venta->detalle[1]];

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $primera->id, 'cantidad' => (float) $primera->cantidad]],
            'Primera tanda');

        $this->assertSame('DEVUELTA_PARCIAL', $venta->fresh()->estado);

        $ultima = Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $segunda->id, 'cantidad' => (float) $segunda->cantidad]],
            'Segunda tanda');

        $this->assertSame('TOTAL', $ultima->tipo);
        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
        $this->assertSame(2, $venta->fresh()->devoluciones()->count());
    }

    // ------------------------------------------------------------- límites

    public function test_no_se_devuelve_mas_de_lo_vendido(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sin devolver');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => (float) $linea->cantidad + 1]],
            'Más de lo que compró');
    }

    public function test_no_se_devuelve_dos_veces_la_misma_unidad(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first(); // cantidad 3

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 3]], 'Todo de esta línea');

        $this->expectException(RuntimeException::class);

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 1]], 'Otra vez');
    }

    public function test_una_venta_anulada_no_admite_devolucion(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Ventas::anular($venta, $this->admin(), 'Error de cobro');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('anulada');

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Tarde');
    }

    public function test_una_venta_ya_devuelta_no_admite_otra_devolucion(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            $venta->detalle->map(fn ($l) => ['venta_detalle_id' => $l->id, 'cantidad' => (float) $l->cantidad])->all(),
            'Todo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('por completo');

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'De nuevo');
    }

    public function test_una_linea_de_otra_venta_se_rechaza(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $otra = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $otra->detalle->first()->id, 'cantidad' => 1]], 'Línea ajena');
    }

    public function test_una_devolucion_sin_cantidades_se_rechaza(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ninguna cantidad');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 0]], 'Nada');
    }

    /** Media unidad de jabón no vuelve al estante. */
    public function test_una_unidad_sin_decimales_rechaza_fracciones(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unidad entera');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1.5]], 'Media unidad');
    }

    // ------------------------------------------------- mercadería dañada

    /**
     * Lo que llega roto se paga pero no vuelve al inventario: el dinero sale y
     * el stock se queda como estaba.
     */
    public function test_lo_que_no_reingresa_devuelve_dinero_pero_no_stock(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0004');

        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->firstWhere('producto_id', $producto->id);
        $stockTrasVenta = (float) $producto->fresh()->stock_actual;

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 2, 'reingresa_stock' => false]],
            'Llegaron vencidas');

        $this->assertSame($stockTrasVenta, (float) $producto->fresh()->stock_actual);
        $this->assertSame('8.00', $devolucion->total);

        // La línea igual cuenta como devuelta para la venta.
        $this->assertSame('2.000', $linea->fresh()->cantidad_devuelta);

        $this->assertDatabaseMissing('movimientos_inventario', [
            'devolucion_id' => $devolucion->id,
            'origen' => 'DEVOLUCION',
        ]);
    }

    // ------------------------------------------------------------------ caja

    /** El dinero devuelto sale del cajón y el arqueo tiene que reflejarlo. */
    public function test_la_devolucion_baja_el_efectivo_esperado(): void
    {
        $sesion = $this->turno(inicial: 200);
        $venta = $this->ventaConDosLineas($sesion);

        $conVenta = $sesion->efectivoEsperado();

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]],
            'Producto en mal estado');

        $esperado = round($conVenta - (float) $devolucion->total, 2);

        $this->assertSame($esperado, $sesion->efectivoEsperado());

        // El cierre de la base tiene que llegar al mismo número.
        $cerrada = Cajas::cerrar($sesion, $this->admin(), $esperado);

        $this->assertSame(number_format($esperado, 2, '.', ''), $cerrada->monto_esperado);
        $this->assertSame('0.00', $cerrada->diferencia);
    }

    public function test_no_se_devuelve_sin_caja_abierta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Cajas::cerrar($sesion, $this->admin(), $sesion->efectivoEsperado());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierta');

        Devoluciones::registrar($venta, $this->admin(), $sesion->fresh(),
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Sin caja');
    }

    // ------------------------------------------------------------------ HTTP

    public function test_la_devolucion_se_registra_de_punta_a_punta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $producto = $this->producto('P-0004');
        $stockTrasVenta = (float) $producto->fresh()->stock_actual;

        $respuesta = $this->actingAs($this->admin())->post("/ventas/{$venta->id}/devolver", [
            'motivo' => 'El cliente devolvió dos unidades por estar vencidas',
            'lineas' => [
                // El formulario manda todas las líneas; las de cantidad 0 se descartan.
                ['venta_detalle_id' => $venta->detalle[0]->id, 'cantidad' => 2, 'reingresa_stock' => 1],
                ['venta_detalle_id' => $venta->detalle[1]->id, 'cantidad' => 0, 'reingresa_stock' => 1],
            ],
        ]);

        $devolucion = Devolucion::where('venta_id', $venta->id)->firstOrFail();

        $respuesta->assertRedirect(route('devoluciones.show', $devolucion));

        $this->assertSame('8.00', $devolucion->total);
        $this->assertSame(1, $devolucion->detalle()->count());
        $this->assertSame($stockTrasVenta + 2, (float) $producto->fresh()->stock_actual);

        $this->actingAs($this->admin())->get("/devoluciones/{$devolucion->id}")->assertOk();
    }

    public function test_la_devolucion_exige_motivo(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->admin())
            ->post("/ventas/{$venta->id}/devolver", [
                'motivo' => '',
                'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('motivo');
    }

    /**
     * Se le devuelve al cliente lo que pagó: base más impuesto. La tasa sale
     * de la línea de venta original, no de la configuración de hoy.
     */
    public function test_el_total_devuelto_incluye_el_impuesto(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first(); // 3 × 3.54, afecta al 13%

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 3]], 'Producto vencido');

        // Base 10.62 + impuesto 1.38 (ROUND(10.62 × 0.13, 2)) = lo que el cliente
        // pagó por esas 3 unidades.
        $this->assertSame('12.00', $devolucion->total);
        $this->assertSame(10.62, $devolucion->base);
        $this->assertSame(1.38, $devolucion->impuesto_devuelto);

        // Coincide con lo que la venta cobró por esa línea.
        $this->assertSame($linea->fresh()->total_linea, $devolucion->total);
    }

    /** La tasa se congela al devolver: si cambia el IVA, lo ya devuelto no. */
    public function test_la_tasa_se_copia_de_la_linea_de_venta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first();

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 1]], 'Una unidad');

        $devuelta = $devolucion->detalle->first();

        $this->assertSame($linea->tasa_impuesto, $devuelta->tasa_impuesto);
        $this->assertTrue($devuelta->afecto_impuesto);
    }

    /** Un producto exonerado se devuelve sin impuesto encima. */
    public function test_un_producto_exonerado_se_devuelve_sin_impuesto(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0011');
        $producto->update(['afecto_impuesto' => false]);

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->admin(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 4, 'precio_unitario' => 1.06]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 4]], 'Galletas rotas');

        $this->assertSame('4.24', $devolucion->total); // 4 × 1.06, sin impuesto
        $this->assertSame(0.0, $devolucion->impuesto_devuelto);
    }

    public function test_queda_auditada_con_su_responsable(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            'Producto equivocado');

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'DEVOLUCION_REGISTRADA',
            'entidad' => 'devoluciones',
            'entidad_id' => $devolucion->id,
            'usuario_id' => $this->admin()->id,
        ]);
    }

    // ------------------------------------------- la devolución y el cajón

    /**
     * Vende una unidad con el método indicado y la devuelve entera.
     *
     * Devuelve también el total de la venta: según el régimen configurado el
     * precio lleva impuesto encima, y el test no debe depender de la tasa.
     *
     * @return array{0: float, 1: float, 2: float} esperado antes, después, y total de la venta
     */
    private function venderYDevolver(SesionCaja $sesion, string $codigoMetodo): array
    {
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 1, 'precio_unitario' => 10]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', $codigoMetodo)->value('id'), 'monto' => null, 'referencia' => 'OP-001']],
        );

        $antes = $sesion->fresh()->efectivoEsperado();

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            'Producto en mal estado');

        return [$antes, $sesion->fresh()->efectivoEsperado(), (float) $venta->fresh()->total];
    }

    /**
     * Del cajón sale lo que entró: una venta cobrada en efectivo devuelve
     * efectivo.
     */
    public function test_devolver_una_venta_en_efectivo_descuenta_del_cajon(): void
    {
        [$antes, $despues, $total] = $this->venderYDevolver($this->turno(), 'EFECTIVO');

        $this->assertEqualsWithDelta($total, $antes - $despues, 0.01,
            'lo cobrado en efectivo y devuelto debería salir entero del cajón');
    }

    /**
     * Y lo que nunca entró, no sale: el reembolso de una venta con tarjeta va
     * por el mismo medio. Descontarlo del cajón dejaba al cajero con un
     * sobrante igual al importe devuelto.
     */
    public function test_devolver_una_venta_con_tarjeta_no_toca_el_cajon(): void
    {
        $sesion = $this->turno();
        $tarjeta = MetodoPago::where('afecta_caja', 0)->firstOrFail();

        [$antes, $despues] = $this->venderYDevolver($sesion, $tarjeta->codigo);

        $this->assertEqualsWithDelta($antes, $despues, 0.01,
            'el cajón no debería moverse: con tarjeta no entró ni salió efectivo');
        // Y sigue siendo el fondo inicial, intacto.
        $this->assertEqualsWithDelta(200, $despues, 0.01);
    }

    /** El arqueo firmado al cerrar tiene que decir lo mismo que la pantalla. */
    public function test_el_cierre_no_inventa_un_sobrante_tras_devolver_con_tarjeta(): void
    {
        $sesion = $this->turno(inicial: 200);
        $tarjeta = MetodoPago::where('afecta_caja', 0)->firstOrFail();

        $this->venderYDevolver($sesion, $tarjeta->codigo);

        // Se cuenta exactamente el fondo inicial: no hubo movimiento de efectivo.
        Cajas::cerrar($sesion->fresh(), $this->admin(), 200);

        $this->assertEqualsWithDelta(200, (float) $sesion->fresh()->monto_esperado, 0.01);
        $this->assertEqualsWithDelta(0, (float) $sesion->fresh()->diferencia, 0.01,
            'contando el fondo intacto no debería aparecer ninguna diferencia');
    }

    /** En una venta mixta sale del cajón solo la parte cobrada en efectivo. */
    public function test_en_una_venta_mixta_solo_sale_del_cajon_la_parte_en_efectivo(): void
    {
        $sesion = $this->turno();
        $tarjeta = MetodoPago::where('afecta_caja', 0)->firstOrFail();

        // 4 en efectivo y el resto con tarjeta. El «resto» lo calcula el
        // servidor, así que el reparto vale con impuesto y sin él.
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 1, 'precio_unitario' => 10]],
            pagos: [
                ['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => 4],
                ['metodo_pago_id' => $tarjeta->id, 'monto' => null, 'referencia' => 'VOUCHER-001'],
            ],
        );

        $antes = $sesion->fresh()->efectivoEsperado();

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            'Producto en mal estado');

        $this->assertEqualsWithDelta(4, $antes - $sesion->fresh()->efectivoEsperado(), 0.01,
            'del cajón solo salen los 4 cobrados en efectivo; el resto se reembolsa por tarjeta');
    }
    // ================================================= una sola caja y reembolso

    private function ventaCon(SesionCaja $sesion, string $codigoMetodo, float $cantidad = 2): Venta
    {
        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => $cantidad]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', $codigoMetodo)->value('id'), 'monto' => null, 'referencia' => 'OP-001']],
        );
    }

    /**
     * El minimarket tiene una caja. El cajero la tiene abierta y el
     * administrador —único con permiso— registra la devolución: el dinero sale
     * del cajón del cajero y se descuenta de ese turno.
     */
    public function test_con_una_sola_caja_el_administrador_devuelve_en_el_turno_del_cajero(): void
    {
        $this->assertSame(1, Caja::count());
        $turnoCajero = $this->turno($this->cajero(), 200);
        $venta = $this->ventaCon($turnoCajero, 'EFECTIVO');
        $antes = $turnoCajero->fresh()->efectivoEsperado();

        $this->actingAs($this->admin())->get("/ventas/{$venta->id}/devolver")
            ->assertOk()->assertSee('turno de cajero1');

        $this->actingAs($this->admin())->post("/ventas/{$venta->id}/devolver", [
            'motivo' => 'Producto vencido en la góndola',
            'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2, 'reingresa_stock' => 0]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $devolucion = Devolucion::where('venta_id', $venta->id)->firstOrFail();
        $this->assertSame($turnoCajero->id, $devolucion->sesion_caja_id);
        $this->assertSame($this->admin()->id, $devolucion->usuario_id);
        $this->assertEqualsWithDelta($antes - (float) $venta->total, $turnoCajero->fresh()->efectivoEsperado(), 0.001);
    }

    /**
     * Lo pagado con tarjeta, QR o billetera casi siempre se devuelve en
     * efectivo. Antes el arqueo no lo restaba y el cajero cerraba con faltante.
     */
    public function test_lo_pagado_con_tarjeta_y_devuelto_en_efectivo_sale_del_cajon(): void
    {
        $sesion = $this->turno(inicial: 200);
        $venta = $this->ventaCon($sesion, 'TARJETA');

        $devolucion = Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]], 'Cliente arrepentido', Devolucion::EFECTIVO);

        $this->assertSame('EFECTIVO', $devolucion->reembolso);
        $this->assertSame((string) $devolucion->total, (string) $devolucion->efectivo);
        $esperado = round(200 - (float) $devolucion->total, 2);
        $this->assertEqualsWithDelta($esperado, $sesion->fresh()->efectivoEsperado(), 0.001);

        // Y el cierre firma lo mismo que la pantalla, con procedimientos y en PHP.
        Cajas::cerrar($sesion->fresh(), $this->admin(), $esperado);
        $this->assertEqualsWithDelta(0, (float) $sesion->fresh()->diferencia, 0.001);
    }

    public function test_por_el_mismo_medio_solo_sale_la_parte_cobrada_en_efectivo(): void
    {
        $sesion = $this->turno(inicial: 200);
        $venta = $this->ventaCon($sesion, 'TARJETA');

        $this->actingAs($this->admin())->post("/ventas/{$venta->id}/devolver", [
            'motivo' => 'Se reembolsa a la tarjeta',
            'reembolso' => 'MISMO_MEDIO',
            'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2, 'reingresa_stock' => 1]],
        ])->assertSessionMissing('error');

        $devolucion = Devolucion::where('venta_id', $venta->id)->firstOrFail();
        $this->assertSame('MISMO_MEDIO', $devolucion->reembolso);
        $this->assertSame('0.00', (string) $devolucion->efectivo);
        $this->assertEqualsWithDelta(200, $sesion->fresh()->efectivoEsperado(), 0.001);
    }

    public function test_no_se_devuelve_en_efectivo_mas_de_lo_que_hay_en_el_cajon(): void
    {
        $sesion = $this->turno(inicial: 0);
        $venta = $this->ventaCon($sesion, 'TARJETA');

        try {
            Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
                [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]], 'Sin efectivo', Devolucion::EFECTIVO);
            $this->fail('se devolvió en efectivo sin efectivo en el cajón');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no alcanza', $e->getMessage());
        }

        $this->assertSame(0, Devolucion::where('venta_id', $venta->id)->count());
        $this->assertSame('COMPLETADA', $venta->fresh()->estado);
    }

    public function test_sin_ninguna_caja_abierta_no_se_registra(): void
    {
        $sesion = $this->turno(inicial: 200);
        $venta = $this->ventaCon($sesion, 'EFECTIVO');
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $this->actingAs($this->admin())->post("/ventas/{$venta->id}/devolver", [
            'motivo' => 'Sin caja abierta',
            'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => 1]],
        ])->assertRedirect(route('caja.index'))->assertSessionHas('error', fn ($m) => str_contains($m, 'ninguna caja abierta'));

        $this->assertSame(0, Devolucion::where('venta_id', $venta->id)->count());
    }

    // ================================================================ plazo

    /** Pasado el plazo configurado, la devolución ya no se registra. */
    public function test_pasado_el_plazo_no_se_registra_la_devolucion(): void
    {
        DB::table('configuracion')->where('clave', 'dias_max_devolucion')->update(['valor' => '7']);
        Config::olvidar();

        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        Venta::whereKey($venta->id)->update(['fecha' => now()->subDays(8)]);
        $venta->refresh();

        $this->assertFalse($venta->dentroDelPlazoDeDevolucion());

        try {
            Devoluciones::registrar($venta, $this->admin(), $sesion,
                [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Llegó tarde con el producto');
            $this->fail('Se registró una devolución fuera de plazo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Pasó el plazo', $e->getMessage());
        }

        $this->actingAs($this->admin())->get(route('devoluciones.create', $venta))
            ->assertRedirect(route('ventas.show', $venta));
        $this->actingAs($this->admin())->get(route('ventas.show', $venta))
            ->assertOk()->assertSee('data-devolucion="fuera-de-plazo"', false);
    }

    public function test_el_ultimo_dia_del_plazo_todavia_se_devuelve(): void
    {
        DB::table('configuracion')->where('clave', 'dias_max_devolucion')->update(['valor' => '7']);
        Config::olvidar();

        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        Venta::whereKey($venta->id)->update(['fecha' => now()->subDays(7)]);

        $devolucion = Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Dentro del plazo justo');

        $this->assertNotNull($devolucion->id);
    }

    // ================================================================ al centavo

    /**
     * Casos reales en que la suma de las líneas devueltas no daba lo cobrado:
     * [cantidad P-0004, cantidad P-0009, descuento].
     *
     * @return array<string, array{0: int, 1: int, 2: float}>
     */
    public static function ventasConDescuento(): array
    {
        return [
            'sobraba un centavo' => [2, 3, 0.37],
            'faltaba un centavo' => [3, 1, 0.50],
            'faltaban dos centavos' => [5, 2, 0.10],
        ];
    }

    #[DataProvider('ventasConDescuento')]
    public function test_devolver_toda_la_venta_devuelve_lo_cobrado_al_centavo(int $a, int $b, float $descuento): void
    {
        $sesion = $this->turno(inicial: 500);
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [
                ['producto_id' => $this->producto('P-0004')->id, 'cantidad' => $a],
                ['producto_id' => $this->producto('P-0009')->id, 'cantidad' => $b],
            ],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            descuento: $descuento,
        );
        $lineas = $venta->detalle->values();

        // Primero una parte y después el resto: el ajuste va en la que cierra.
        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion->fresh(),
            [['venta_detalle_id' => $lineas[0]->id, 'cantidad' => 1]], 'Primera parte');
        $ultima = Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion->fresh(), [
            ['venta_detalle_id' => $lineas[0]->id, 'cantidad' => $a - 1],
            ['venta_detalle_id' => $lineas[1]->id, 'cantidad' => $b],
        ], 'El resto de la compra');

        $venta->refresh();
        $this->assertSame('DEVUELTA', $venta->estado);
        $this->assertSame($venta->total, $venta->total_devuelto);
        $this->assertSame((float) $venta->total, round((float) $venta->devoluciones()->sum('total'), 2));
        $this->assertSame((float) $ultima->total, (float) $ultima->efectivo);

        // Lo que entró y lo que salió del cajón se cancelan: queda el fondo.
        $this->assertSame(500.0, $sesion->fresh()->efectivoEsperado());
    }
}
