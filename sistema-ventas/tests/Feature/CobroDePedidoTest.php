<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Cobrar una cuenta.
 *
 * Es la prueba que más importa del módulo: el cobro NO reinventa el dinero, lo
 * traduce a una venta normal con `Ventas::registrar()`. Así que lo que se
 * comprueba aquí es la equivalencia —una cuenta cobrada tiene que dar la misma
 * venta que el mostrador directo— y que lo propio del pedido (fusionar tandas,
 * no cobrar lo cancelado, cerrarse una sola vez) esté bien.
 */
class CobroDePedidoTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function plato(string $codigo = 'P-0004'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    private function turno(?Usuario $usuario = null, float $inicial = 100): SesionCaja
    {
        $usuario ??= $this->usuario('admin');

        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial))->fresh();
    }

    /**
     * Un pedido abierto con los platos indicados (el que queda para volver a cobrar
     * al anular su venta: en el local, todo lo demás se cobra al pedirlo).
     *
     * @param  array<int, array{0: Producto, 1: int, 2?: string}>  $lineas
     */
    private function cuenta(array $lineas): Pedido
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);

        foreach ($lineas as [$plato, $cantidad]) {
            Pedidos::agregarLinea($pedido, $plato, $cantidad, null, $cajero);
        }

        return $pedido->fresh();
    }

    // ------------------------------------------------------- la equivalencia

    /**
     * Una cuenta cobrada y una venta de mostrador con lo mismo dentro tienen
     * que salir idénticas: mismos totales, mismo impuesto, mismo comprobante.
     * Si dejan de coincidir, el cobro de pedidos se convirtió en un segundo
     * camino del dinero, que es justo lo que este diseño evita.
     */
    public function test_cobrar_un_pedido_da_la_misma_venta_que_el_mostrador(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $plato = $this->plato();

        $pedido = $this->cuenta([[$plato, 3]]);
        $delPedido = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();

        $delMostrador = Ventas::registrar(
            sesion: $this->turno($admin),
            usuario: $admin,
            lineas: [['producto_id' => $plato->id, 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
        )->fresh();

        foreach (['subtotal', 'descuento', 'impuesto', 'total', 'estado', 'impuesto_incluido'] as $campo) {
            $this->assertSame(
                (string) $delMostrador->$campo,
                (string) $delPedido->$campo,
                "«{$campo}» no coincide entre el cobro del pedido y el mostrador",
            );
        }

        $this->assertNotNull($delPedido->comprobante);
        $this->assertSame(
            $delMostrador->comprobante->serie_id,
            $delPedido->comprobante->serie_id,
        );
        $this->assertSame(1, $delPedido->detalle()->count());
    }

    public function test_el_pedido_queda_cerrado_con_su_venta(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);

        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ]);

        $pedido = $pedido->fresh();
        $this->assertSame(Pedido::CERRADO, $pedido->estado);
        $this->assertSame($venta->id, $pedido->venta?->id);
        $this->assertNotNull($pedido->fecha_cierre);
        $this->assertSame($admin->id, (int) $pedido->cerrado_por);

        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_COBRADO', 'entidad_id' => $pedido->id]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'VENTA_REGISTRADA', 'entidad_id' => $venta->id]);
    }

    // --------------------------------------------------- fusión de las tandas

    /**
     * Cada tanda es su propia línea del pedido, pero `venta_detalle` exige una
     * línea por producto: se agrupan al cobrar y el total tiene que seguir
     * siendo exactamente la suma de lo servido, aunque el precio del catálogo
     * haya cambiado a mitad de turno.
     */
    public function test_las_tandas_del_mismo_plato_se_juntan_al_precio_de_lo_servido(): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($admin);
        $plato = $this->plato();
        $precioViejo = (float) $plato->precio_venta;

        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::agregarLinea($pedido, $plato, 2, null, $cajero);

        // El precio sube a mitad de turno: la tanda siguiente sale más cara y
        // la primera tiene que seguir cobrándose como se cantó.
        $plato->forceFill(['precio_venta' => round($precioViejo + 2, 2)])->save();
        Pedidos::agregarLinea($pedido->fresh(), $plato->fresh(), 1, null, $cajero);

        $servido = round((float) $pedido->fresh()->detalle()->sum('importe'), 2);
        $agrupadas = Pedidos::lineasAgrupadas($pedido->fresh());

        $this->assertCount(1, $agrupadas);
        $this->assertSame(3.0, $agrupadas[0]['cantidad']);

        $venta = Pedidos::cobrar($pedido->fresh(), $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();

        $this->assertSame(1, $venta->detalle()->count());

        /*
         * Al céntimo de lo servido. No exactamente igual, y es a propósito: el
         * precio del agrupado sale de SUM(importe)/SUM(cantidad) redondeado a
         * dos decimales, y la venta vuelve a multiplicarlo por la cantidad. Con
         * dos tandas a precios distintos esa ida y vuelta puede dejar un
         * céntimo de diferencia. La alternativa era cobrar al precio del
         * catálogo de ahora, que se desvía mucho más, o partir la venta en una
         * línea por tanda, que `venta_detalle` no admite.
         */
        $this->assertLessThanOrEqual(
            1,
            abs((int) round($servido * 100) - (int) round((float) $venta->subtotal * 100)),
            'el cobro se despegó más de un céntimo de lo que se sirvió',
        );
    }

    /** Impuesto al 13 %, sumado o incluido, con un plato que lo lleva y otro exento. */
    private function impuesto(string $incluido): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => $incluido]);
        Config::olvidar();

        Producto::where('codigo', 'P-0004')->update(['precio_venta' => 4.00, 'afecto_impuesto' => 1]);
        Producto::where('codigo', 'P-0009')->update(['precio_venta' => 2.50, 'afecto_impuesto' => 0]);
        // El impuesto de los platos cambió dentro de la prueba.
        Pedidos::olvidar();
    }

    /**
     * La cuenta como la lee el cliente: con el impuesto sumado aparte, cada
     * línea que lo lleva sale con él puesto, la exenta tal cual, y el total
     * es el que después cobra la venta.
     */
    public function test_la_cuenta_muestra_cada_linea_como_la_paga_el_cliente(): void
    {
        $this->impuesto('0');
        $admin = $this->usuario('admin');

        $pedido = $this->cuenta([[$this->plato('P-0004'), 3], [$this->plato('P-0009'), 2]]);
        [$afecta, $exenta] = $pedido->detalle()->orderBy('id')->pluck('id')->all();

        $cuenta = Pedidos::cuentaDe($pedido->fresh());

        $this->assertSame([$afecta => 13.56, $exenta => 5.0], $cuenta['importes']);
        $this->assertSame(18.56, $cuenta['total']);

        $venta = Pedidos::cobrar($pedido->fresh(), $this->turno($admin), $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();
        $this->assertSame($cuenta['total'], (float) $venta->total);

        // Con el precio que ya incluye el impuesto, la línea es su importe.
        $this->impuesto('1');
        $otra = $this->cuenta([[$this->plato('P-0004'), 3]]);
        $cuenta = Pedidos::cuentaDe($otra->fresh());

        $this->assertSame([$otra->detalle()->value('id') => 12.0], $cuenta['importes']);
        $this->assertSame(12.0, $cuenta['total']);
    }

    /** Un pedido recién abierto vale cero y no revienta: la lista «Volver a cobrar» lo muestra igual. */
    public function test_la_cuenta_vacia_vale_cero(): void
    {
        $pedido = Pedidos::abrir(Pedido::LOCAL, $this->usuario('cajero1'));

        $this->assertSame(['importes' => [], 'total' => 0.0], Pedidos::cuentaDe($pedido));
    }

    /**
     * Qué platos llevan impuesto se lee una vez y se guarda; `olvidar()` lo
     * vuelve a leer. Sin eso, una prueba que cambiaba el impuesto de un plato
     * le dejaba la lista vieja a la siguiente.
     */
    public function test_lo_que_lleva_impuesto_se_vuelve_a_leer_al_olvidarlo(): void
    {
        $this->impuesto('0');
        $pedido = $this->cuenta([[$this->plato('P-0004'), 1]]);

        $this->assertSame(4.52, Pedidos::totalDe($pedido));

        Producto::where('codigo', 'P-0004')->update(['afecto_impuesto' => 0]);
        Pedidos::olvidar();

        $this->assertSame(4.0, Pedidos::totalDe($pedido));
    }

    /**
     * Un pedido para llevar con el cobro anulado se cobra desde su pantalla: sale de la
     * lista del punto de venta, la venta lleva el comprobante a nombre
     * genérico y el pedido queda enlazado a ella.
     */
    public function test_un_pedido_para_llevar_se_cobra_desde_la_pantalla(): void
    {
        $admin = $this->usuario('admin');
        $this->turno($admin);
        $pedido = Pedidos::abrir(Pedido::LLEVAR, $admin, nombreCliente: 'Rosaura Llevar');
        Pedidos::agregarLinea($pedido, $this->plato(), 2, 'para llevar, sin cubiertos', $admin);
        Pedidos::agregarLinea($pedido, $this->plato('P-0005'), 1, null, $admin);
        $total = Pedidos::totalesDe($pedido->fresh())['total'];

        $this->actingAs($admin)->get(route('pos.index'))->assertOk()->assertSee('Rosaura Llevar')
            ->assertViewHas('porCobrar', fn ($lista) => $lista->contains('id', $pedido->id));

        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), [
            'total_esperado' => number_format($total, 2, '.', ''),
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto_recibido' => number_format($total + 5, 2, '.', '')]],
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');

        $pedido = $pedido->fresh();
        $venta = $pedido->venta;

        $this->assertSame(Pedido::CERRADO, $pedido->estado);
        $this->assertSame(Pedido::LLEVAR, $pedido->tipo);
        $this->assertSame($total, (float) $venta->total);
        $this->assertSame(2, $venta->detalle()->count());
        $this->assertNull($venta->cliente_id);
        $this->assertNotNull($venta->comprobante);
        $this->assertSame(5.0, (float) $venta->pagos()->value('vuelto'));

        // (El nombre sigue en pantalla en el aviso de «cobrado»: se mira la lista.)
        $this->actingAs($admin)->get(route('pos.index'))->assertOk()
            ->assertViewHas('porCobrar', fn ($lista) => ! $lista->contains('id', $pedido->id));
        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_COBRADO', 'entidad_id' => $pedido->id]);
    }

    /** Lo cancelado se le pidió a la cocina, pero al cliente no se le cobra. */
    public function test_los_platos_cancelados_no_se_cobran(): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($admin);

        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $buena = Pedidos::agregarLinea($pedido, $this->plato(), 2, null, $cajero);
        $mala = Pedidos::agregarLinea($pedido, $this->plato('P-0009'), 1, null, $cajero);
        Pedidos::actualizarEstadoLinea($mala, PedidoDetalle::CANCELADO, $cajero);

        $venta = Pedidos::cobrar($pedido->fresh(), $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();

        $this->assertSame(1, $venta->detalle()->count());
        $this->assertSame($buena->producto_id, (int) $venta->detalle()->value('producto_id'));
        $this->assertSame(
            round((float) $buena->fresh()->importe, 2),
            round((float) $venta->subtotal, 2),
        );

        // Y la línea cancelada sigue en el pedido: quedó el rastro.
        $this->assertNotNull(PedidoDetalle::find($mala->id));
    }

    /** Una cuenta sin nada cobrable se cancela, no se cobra. */
    public function test_una_cuenta_vacia_o_toda_cancelada_no_se_cobra(): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($admin);

        $vacia = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $this->assertThrows(
            fn () => Pedidos::cobrar($vacia, $turno, $admin, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]),
            RuntimeException::class,
        );
        $this->assertTrue($vacia->fresh()->estaAbierto());

        // Pero cancelarlo sí se puede: el cliente se fue sin consumir.
        $this->assertSame(Pedido::CANCELADO, Pedidos::cancelar($vacia->fresh(), $cajero, 'Se fueron')->estado);

        $todaCancelada = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($todaCancelada, $this->plato(), 1, null, $cajero);
        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::CANCELADO, $cajero);

        $this->assertThrows(
            fn () => Pedidos::cobrar($todaCancelada->fresh(), $turno, $admin, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]),
            RuntimeException::class,
        );
    }

    // ---------------------------------------------------- la maquinaria heredada

    /** Pagos mixtos: el cobro los hereda de `Ventas::registrar` sin repetir nada. */
    public function test_la_cuenta_se_paga_en_efectivo_y_por_qr(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 3]]);

        $total = Pedidos::totalesDe($pedido)['total'];
        $mitad = round($total / 2, 2);

        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno->fresh(), $admin, $mitad), $admin);

        $venta = Pedidos::cobrar($pedido, $this->turno($admin), $admin, [
            ['metodo_pago_id' => $this->metodo('QR'), 'monto' => $mitad, 'cobro_qr_id' => $cobro->id],
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();

        $this->assertSame(2, $venta->pagos()->count());
        $this->assertSame(round($total, 2), round((float) $venta->pagos()->sum('monto'), 2));
        $this->assertSame($venta->id, (int) $cobro->fresh()->venta_id);
    }

    /** El descuento y la observación llegan a la venta. */
    public function test_la_cuenta_admite_descuento_y_observacion(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 3]]);

        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ], descuento: 1.00, observacion: 'Sin cubiertos')->fresh();

        $this->assertGreaterThan(0, (float) $venta->descuento + (float) $venta->descuento_precio_final);
        $this->assertSame('Sin cubiertos', $venta->observacion);
    }

    /** El cliente que se indica al cobrar es el de la venta y el del comprobante; el pedido no guarda uno propio. */
    public function test_el_comprobante_sale_a_nombre_del_cliente_del_cobro(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $cliente = Cliente::where('activo', 1)->firstOrFail();

        $pedido = $this->cuenta([[$this->plato(), 2]]);
        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ], cliente: $cliente)->fresh();

        $this->assertSame($cliente->id, (int) $venta->cliente_id);
        $this->assertSame($cliente->id, (int) $venta->comprobante->cliente_id);
    }

    // ------------------------------------------------------------- las guardas

    /** Cobrar exige turno de caja abierto: sin él no hay dónde imputar el dinero. */
    public function test_sin_caja_abierta_no_se_cobra(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);

        $abierto = $turno->fresh();
        // Con el pedido todavía sin volver a cobrar: se cierra sabiéndolo.
        Cajas::cerrar($abierto, $admin, (float) $abierto->efectivoEsperado(), null, 0, $abierto->huella(), conCuentasAbiertas: true);

        $this->assertThrows(
            fn () => Pedidos::cobrar($pedido, $turno->fresh(), $admin, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]),
            RuntimeException::class,
        );

        // Y por HTTP, quien no tiene turno abierto va a la caja, no a un error.
        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))
            ->assertRedirect(route('caja.index'));
    }

    /**
     * La misma cuenta no se cobra dos veces. `uq_venta_pedido_cobrado` (una
     * sola venta COMPLETADA por pedido) lo remata en la base, pero el bloqueo del pedido dentro de la transacción
     * es lo que da el mensaje entendible.
     */
    public function test_la_misma_cuenta_no_se_cobra_dos_veces(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);

        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ]);

        $this->assertThrows(
            fn () => Pedidos::cobrar($pedido->fresh(), $this->turno($admin), $admin, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]),
            RuntimeException::class,
        );

        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $pedido->id)->where('estado', 'COMPLETADA')->count());
        $this->assertSame($venta->id, $pedido->fresh()->venta?->id);
    }

    /** Una cuenta cancelada tampoco se cobra. */
    public function test_una_cuenta_cancelada_no_se_cobra(): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($admin);

        $pedido = $this->cuenta([[$this->plato(), 2]]);
        Pedidos::cancelar($pedido, $cajero, 'El cliente se fue');

        $this->assertThrows(
            fn () => Pedidos::cobrar($pedido->fresh(), $turno, $admin, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]),
            RuntimeException::class,
        );
    }

    /**
     * La carrera de verdad: se agrega un plato mientras el cajero cobra.
     * El plato entra ANTES de que la transacción del cobro empiece, así que
     * tiene que cobrarse; y el que llegue después, con la cuenta ya cerrada,
     * tiene que rebotar en vez de servirse gratis.
     */
    public function test_un_plato_agregado_antes_de_cobrar_entra_y_el_de_despues_rebota(): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($admin);
        $plato = $this->plato();

        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::agregarLinea($pedido, $plato, 1, null, $cajero);
        Pedidos::agregarLinea($pedido->fresh(), $this->plato('P-0009'), 1, null, $cajero);

        $venta = Pedidos::cobrar($pedido->fresh(), $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ])->fresh();

        $this->assertSame(2, $venta->detalle()->count());

        $this->assertThrows(
            fn () => Pedidos::agregarLinea($pedido->fresh(), $plato, 1, null, $cajero),
            RuntimeException::class,
        );
    }

    // ----------------------------------------------------------- la anulación

    /**
     * Anular la venta de un pedido lo deja para volver a cobrar, con sus platos y su
     * número, para cobrarlo otra vez. El caso real: el cajero cobró con la
     * forma de pago equivocada y anula para rehacer. Antes el pedido quedaba
     * cerrado apuntando a una venta anulada, el cobro respondía «ya se cobró»
     * y había que volver a teclear todo en el mostrador, con otro número.
     *
     * Importa también porque `sp_anular_venta` se reescribió al quitar el
     * inventario: si quedara algún resto de aquello, esto revienta.
     */
    public function test_anular_la_venta_reabre_el_pedido_para_cobrarlo_de_nuevo(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2], [$this->plato('P-0005'), 1]]);
        Pedidos::actualizarEstadoLinea($pedido->detalle()->first(), PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));

        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ]);

        // Las líneas como quedaron al cobrar: el refresco (Bebidas, que no pasa
        // por la cocina) se entregó con el ticket. Anular no las toca.
        $lineas = $pedido->detalle()->orderBy('id')->get(['id', 'cantidad', 'precio_unitario', 'estado_cocina'])->toArray();
        $this->assertSame(PedidoDetalle::ENTREGADO, $lineas[1]['estado_cocina']);

        Ventas::anular($venta->fresh(), $admin, 'Se cobró en efectivo y pagó con tarjeta');

        $this->assertSame('ANULADA', $venta->fresh()->estado);
        // `Venta::comprobante` es el documento válido hoy, y un anulado ya no lo
        // es: el documento sigue en `comprobantes`, con su correlativo intacto.
        $this->assertNull($venta->fresh()->comprobante);
        $this->assertSame('ANULADO', $venta->comprobantes()->orderByDesc('id')->value('estado'));

        $pedido = $pedido->fresh();
        $this->assertSame(Pedido::ABIERTO, $pedido->estado);
        $this->assertNull($pedido->venta);
        $this->assertNull($pedido->fecha_cierre);
        $this->assertNull($pedido->cerrado_por);
        $this->assertSame($lineas, $pedido->detalle()->orderBy('id')->get(['id', 'cantidad', 'precio_unitario', 'estado_cocina'])->toArray());

        $rastro = DB::table('auditoria')->where('accion', 'PEDIDO_REABIERTO')->where('entidad_id', $pedido->id)->first();
        $this->assertNotNull($rastro);
        $this->assertSame($venta->id, json_decode($rastro->detalle, true)['venta_anulada']);

        // Y se cobra otra vez, ahora como corresponde.
        $otra = Pedidos::cobrar($pedido, $turno->fresh(), $admin, [
            ['metodo_pago_id' => $this->metodo('TARJETA'), 'monto' => null, 'referencia' => 'OP-778899'],
        ]);

        $this->assertSame((string) $venta->fresh()->total, (string) $otra->fresh()->total);
        $this->assertSame(Pedido::CERRADO, $pedido->fresh()->estado);
        $this->assertSame($otra->id, $pedido->fresh()->venta?->id);
    }

    /** Por la pantalla: se avisa antes y después, y el pedido queda en «Volver a cobrar» del punto de venta. */
    public function test_la_anulacion_por_pantalla_avisa_que_la_cuenta_vuelve(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        $pedido = Pedidos::abrir(Pedido::LLEVAR, $admin, nombreCliente: 'Ana');
        Pedidos::agregarLinea($pedido, $this->plato(), 1, null, $admin);

        $venta = Pedidos::cobrar($pedido, $turno, $admin, [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ]);

        $this->actingAs($admin)->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('data-anular-pedido', false)
            ->assertSee('queda para volver a cobrar')
            ->assertSee('Volver a cobrar');

        $this->actingAs($admin)->post(route('ventas.anular', $venta), [
            'motivo_anulacion' => 'Se cobró con la forma de pago equivocada',
        ])->assertSessionHas('exito', fn (string $m) => str_contains($m, 'queda para volver a cobrar') && str_contains($m, '«Volver a cobrar»'));

        $this->assertTrue($pedido->fresh()->estaAbierto());
        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk();
    }

    // ---------------------------------------------------------------- por HTTP

    /** El circuito completo por HTTP, que es como lo usa el cajero. */
    public function test_el_cajero_cobra_la_cuenta_desde_la_pantalla(): void
    {
        $admin = $this->usuario('admin');
        $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);
        $total = Pedidos::totalesDe($pedido)['total'];

        // El total lo pinta Alpine con el descuento en vivo; lo que renderiza
        // el servidor es el detalle fusionado y su subtotal.
        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('¿Cómo paga?')
            ->assertSee($this->plato()->nombre)
            ->assertSee(Config::importe(Pedidos::totalesDe($pedido)['subtotal']));

        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), [
            'total_esperado' => number_format($total, 2, '.', ''),
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');

        $this->assertSame(Pedido::CERRADO, $pedido->fresh()->estado);
    }

    /**
     * Un doble clic en «Cobrar» manda dos veces el mismo número de envío: la
     * cuenta se cobra una sola vez. Es el sitio donde ese middleware más
     * importa, porque detrás hay dinero y un comprobante.
     */
    public function test_un_doble_envio_cobra_la_cuenta_una_sola_vez(): void
    {
        $admin = $this->usuario('admin');
        $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);
        $total = Pedidos::totalesDe($pedido)['total'];

        $datos = [
            '_envio' => 'envio-de-prueba-pedido-1',
            'total_esperado' => number_format($total, 2, '.', ''),
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ];

        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('name="_envio"', false);

        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), $datos)
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), $datos)
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $pedido->id)->where('estado', 'COMPLETADA')->count());

        // Si el primer envío no pasó la validación, el mismo formulario se
        // corrige y se reenvía: el número se libera.
        $otro = $this->cuenta([[$this->plato(), 1]]);
        $envio2 = ['_envio' => 'envio-de-prueba-pedido-2'] + $datos;
        $sinPagos = $envio2;
        unset($sinPagos['pagos']);

        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $otro), $sinPagos)
            ->assertSessionHasErrors('pagos');
        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $otro), [
            'total_esperado' => number_format(Pedidos::totalesDe($otro)['total'], 2, '.', ''),
        ] + $envio2)->assertSessionHasNoErrors();

        $this->assertSame(Pedido::CERRADO, $otro->fresh()->estado);
    }

    /**
     * El total que vio el cajero tiene que ser el que suma la venta: si la
     * cuenta cambió mientras cobraba, la venta no se registra.
     */
    public function test_si_el_total_cambio_mientras_cobraba_la_venta_no_entra(): void
    {
        $admin = $this->usuario('admin');
        $this->turno($admin);
        $pedido = $this->cuenta([[$this->plato(), 2]]);

        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), [
            'total_esperado' => '0.01',
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ])->assertSessionHas('error');

        $this->assertTrue($pedido->fresh()->estaAbierto());
    }

    /** El descuento por encima del tope necesita autorización, igual que en el mostrador. */
    public function test_un_descuento_por_encima_del_tope_pide_autorizacion(): void
    {
        $cajero = $this->usuario('cajero1');
        $this->turno($cajero);
        $pedido = $this->cuenta([[$this->plato(), 2]]);
        $subtotal = Pedidos::totalesDe($pedido)['subtotal'];

        $this->assertFalse($cajero->tienePermiso('ventas.descuento'));

        $this->actingAs($cajero)->post(route('pedidos.cobrar.store', $pedido), [
            'descuento' => number_format($subtotal, 2, '.', ''),
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ])->assertSessionHas('error');

        $this->assertTrue($pedido->fresh()->estaAbierto());
    }
}
