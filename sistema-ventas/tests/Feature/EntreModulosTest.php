<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\CobroQr;
use App\Models\Compra;
use App\Models\Devolucion;
use App\Models\DevolucionCompra;
use App\Models\Lote;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\TomaInventarioDetalle;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Compras;
use App\Services\Devoluciones;
use App\Services\Inventario;
use App\Services\Lotes;
use App\Services\TomasInventario;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Fallos que aparecían solo al combinar módulos (revisión del 16/09/2026).
 * Cada prueba reproduce la secuencia que rompía la cuenta.
 */
class EntreModulosTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function perecedero(float $stock = 0): Producto
    {
        $p = Producto::activos()->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        Lote::where('producto_id', $p->id)->delete();
        $p->forceFill(['controla_vencimiento' => 1, 'contenido_empaque' => null, 'nombre_empaque' => null, 'stock_actual' => $stock])->save();

        return $p->fresh();
    }

    private function comun(float $stock = 12): Producto
    {
        $p = Producto::activos()->where('controla_vencimiento', 0)
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        $p->forceFill(['stock_actual' => $stock, 'contenido_empaque' => null, 'nombre_empaque' => null])->save();

        return $p->fresh();
    }

    // ================================================================ toma en papel y ajustes

    /**
     * Se contó en papel a las 10:00 (hay 10, el sistema decía 12), a las 11:00
     * alguien ajustó el stock a 10, y a las 12:00 se cargó el papel. Antes el
     * cierre restaba otra vez los 2 y dejaba 8.
     */
    public function test_un_conteo_en_papel_no_aplica_de_nuevo_un_ajuste_hecho_despues(): void
    {
        $almacen = $this->usuario('almacen');
        $this->actingAs($almacen);
        $hoy = now()->startOfDay();

        Carbon::setTestNow($hoy->copy()->setTime(8, 0));
        $producto = $this->comun(0);
        Inventario::ajuste($producto, 12, 'Stock de partida');

        Carbon::setTestNow($hoy->copy()->setTime(9, 0));
        $toma = TomasInventario::abrir($almacen);

        Carbon::setTestNow($hoy->copy()->setTime(11, 0));
        Inventario::ajuste($producto->fresh(), 10, 'Faltaban dos');

        Carbon::setTestNow($hoy->copy()->setTime(12, 0));
        $linea = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $producto->id)->firstOrFail();

        try {
            TomasInventario::contar($linea, $almacen, 10, $hoy->copy()->setTime(10, 0));
            TomasInventario::cerrar($toma->fresh(), $almacen);
        } catch (RuntimeException) {
            // Rechazar el conteo viejo también es correcto: lo que no puede
            // pasar es que la diferencia se aplique dos veces.
        }

        $this->assertSame(10.0, (float) $producto->fresh()->stock_actual);
    }

    /** El cierre de la toma anota a quien la cierra aunque no haya sesión web (consola, cola). */
    public function test_el_cierre_de_la_toma_no_depende_de_la_sesion_web(): void
    {
        $almacen = $this->usuario('almacen');
        $this->actingAs($almacen);
        $producto = $this->comun(12);

        $toma = TomasInventario::abrir($almacen);
        $linea = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $producto->id)->firstOrFail();
        TomasInventario::contar($linea, $almacen, 11);

        Auth::logout();
        TomasInventario::cerrar($toma->fresh(), $almacen);

        $this->assertSame(11.0, (float) $producto->fresh()->stock_actual);
        $this->assertSame($almacen->id, (int) DB::table('movimientos_inventario')->where('producto_id', $producto->id)->orderByDesc('id')->value('usuario_id'));
    }

    // ================================================================ vencimiento en todas las entradas

    /** La compra de un producto con control de vencimiento exige la fecha, igual que el ingreso suelto. */
    public function test_la_compra_exige_vencimiento_a_los_perecederos(): void
    {
        $producto = $this->perecedero();

        $sinFecha = ['proveedor_id' => Proveedor::firstOrFail()->id, 'documento_externo' => 'F-SIN-FECHA', 'lineas' => [
            ['producto_id' => $producto->id, 'cantidad' => 5, 'costo_unitario' => 2],
        ]];

        $this->actingAs($this->usuario('almacen'))->post(route('compras.store'), $sinFecha)
            ->assertSessionHasErrors('lineas.0.vence');

        $vencida = $sinFecha;
        $vencida['lineas'][0]['vence'] = now()->subDay()->toDateString();
        $this->actingAs($this->usuario('almacen'))->post(route('compras.store'), $vencida)
            ->assertSessionHasErrors('lineas.0.vence');

        $this->assertSame(0.0, (float) $producto->fresh()->stock_actual);

        // Y un producto sin control sigue entrando sin fecha.
        $comun = $this->comun(0);
        $this->actingAs($this->usuario('almacen'))->post(route('compras.store'), [
            'proveedor_id' => Proveedor::firstOrFail()->id, 'documento_externo' => 'F-COMUN',
            'lineas' => [['producto_id' => $comun->id, 'cantidad' => 5, 'costo_unitario' => 2]],
        ])->assertSessionHasNoErrors();
    }

    /** Lo que el proveedor repone de un perecedero entra con su fecha. */
    public function test_la_reposicion_del_proveedor_exige_vencimiento_a_los_perecederos(): void
    {
        $almacen = $this->usuario('almacen');
        $this->actingAs($almacen);
        $producto = $this->perecedero();
        $compra = Compras::registrar($almacen, Proveedor::firstOrFail(), [
            ['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => 2, 'vence' => now()->addMonth()->toDateString()],
        ], 'F-REPO');
        $detalle = $compra->detalle()->firstOrFail();

        // Cambio en el momento, sin fecha para lo repuesto.
        $this->post(route('devoluciones-compra.store', $compra), [
            'motivo' => 'VENCIMIENTO', 'espera' => 'REPUESTO',
            'lineas' => [['compra_detalle_id' => $detalle->id, 'cantidad' => 2]],
        ])->assertSessionHasErrors('lineas.0.vence_repuesto');

        // Reposición posterior, sin fecha.
        $this->post(route('devoluciones-compra.store', $compra), [
            'motivo' => 'VENCIMIENTO', 'espera' => 'PENDIENTE',
            'lineas' => [['compra_detalle_id' => $detalle->id, 'cantidad' => 2]],
        ])->assertSessionHasNoErrors();
        $devolucion = DevolucionCompra::where('compra_id', $compra->id)->latest('id')->firstOrFail();

        $this->post(route('devoluciones-compra.reponer', $devolucion), [
            'lineas' => [['linea_id' => $devolucion->detalle()->firstOrFail()->id, 'cantidad' => 2]],
        ])->assertSessionHasErrors('lineas.0.vence');

        $this->assertSame((float) $producto->fresh()->stock_actual, (float) Lote::where('producto_id', $producto->id)->sum('cantidad_actual'));
    }

    /** Dar de alta un perecedero con stock inicial pide la fecha de ese stock. */
    public function test_el_alta_de_un_perecedero_con_stock_pide_vencimiento(): void
    {
        $datos = [
            'categoria_id' => Categoria::firstOrFail()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->firstOrFail()->id,
            'proveedor_id' => Proveedor::firstOrFail()->id,
            'codigo' => 'P-9901', 'nombre' => 'Yogur de prueba',
            'precio_compra' => '5.00', 'precio_venta' => '8.00', 'afecto_impuesto' => 1,
            'stock_minimo' => '2', 'activo' => 1, 'controla_vencimiento' => 1, 'stock_inicial' => '6',
        ];

        $this->actingAs($this->usuario('admin'))->post('/productos', $datos)->assertSessionHasErrors('vence');
        $this->assertFalse(Producto::where('codigo', 'P-9901')->exists());

        $this->actingAs($this->usuario('admin'))->post('/productos', ['vence' => now()->addMonth()->toDateString()] + $datos)
            ->assertSessionHasNoErrors();
        $nuevo = Producto::where('codigo', 'P-9901')->firstOrFail();
        $this->assertSame(0.0, Lotes::sinFecha($nuevo));
    }

    // ================================================================ atomicidad

    /** Si el lote no se puede guardar, tampoco queda el stock: antes quedaba el kardex sin su lote. */
    public function test_un_ingreso_que_falla_al_abrir_el_lote_no_deja_stock_suelto(): void
    {
        $this->actingAs($this->usuario('almacen'));
        $producto = $this->perecedero();
        $movimientos = DB::table('movimientos_inventario')->where('producto_id', $producto->id)->count();

        // Un código de lote más largo que la columna hace fallar el INSERT de lotes.
        try {
            Inventario::ingreso($producto, 5, costoUnitario: 2, vence: now()->addMonth()->toDateString(), lote: str_repeat('X', 500));
        } catch (\Throwable) {
        }

        $this->assertSame(0.0, (float) $producto->fresh()->stock_actual);
        $this->assertSame($movimientos, DB::table('movimientos_inventario')->where('producto_id', $producto->id)->count());
    }

    // ================================================================ dinero y pantallas

    private function turnoDe(Usuario $usuario, float $inicial = 100): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial))->fresh();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    private function venta(SesionCaja $turno, Usuario $usuario, float $cantidad = 1, ?array $pagos = null): Venta
    {
        return Ventas::registrar(
            sesion: $turno->fresh(),
            usuario: $usuario,
            lineas: [['producto_id' => $this->comun(80)->id, 'cantidad' => $cantidad]],
            pagos: $pagos ?? [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
        );
    }

    /** Los totales del listado de ventas siguen al filtro de estado y a la búsqueda, como la tabla. */
    public function test_los_totales_del_listado_de_ventas_siguen_los_filtros(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($admin);
        $buena = $this->venta($turno, $admin, 2);
        $anulada = $this->venta($turno, $admin, 1);
        Ventas::anular($anulada->fresh(), $admin, 'Prueba de filtros');

        $resumen = fn (array $q) => $this->actingAs($admin)->get(route('ventas.index', $q))->assertOk()->viewData('resumen');

        $soloAnuladas = $resumen(['estado' => 'ANULADA', 'buscar' => '#'.$anulada->id]);
        $this->assertSame(0, $soloAnuladas['operaciones']);
        $this->assertSame(0.0, $soloAnuladas['vendido']);
        $this->assertSame(1, $soloAnuladas['anuladas']);

        $unaSola = $resumen(['buscar' => '#'.$buena->id]);
        $this->assertSame(1, $unaSola['operaciones']);
        $this->assertSame((float) $buena->total, $unaSola['vendido']);
    }

    /** Los totales de devoluciones siguen al filtro de tipo y a la búsqueda. */
    public function test_los_totales_del_listado_de_devoluciones_siguen_los_filtros(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($admin);
        $v1 = $this->venta($turno, $admin, 3);
        $v2 = $this->venta($turno, $admin, 1);

        $parcial = Devoluciones::registrar($v1->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $v1->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'FILTRO-XYZ parcial', Devolucion::EFECTIVO);
        $total = Devoluciones::registrar($v2->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $v2->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'FILTRO-XYZ total', Devolucion::EFECTIVO);

        $resumen = $this->actingAs($admin)->get(route('devoluciones.index', ['tipo' => 'TOTAL', 'buscar' => 'FILTRO-XYZ']))
            ->assertOk()->viewData('resumen');

        $this->assertSame(1, $resumen['operaciones']);
        $this->assertSame((float) $total->total, $resumen['devuelto']);
        $this->assertNotSame((float) $parcial->total + (float) $total->total, $resumen['devuelto']);
    }

    /** El administrador registra en el turno del cajero el egreso que supera su tope: el botón tiene que estar. */
    public function test_el_administrador_ve_como_registrar_un_movimiento_en_el_turno_del_cajero(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turnoDe($cajero);

        $this->actingAs($this->usuario('admin'))->get(route('caja.show', $turno))->assertOk()->assertSee('Registrar movimiento');
        $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()->assertSee('Registrar movimiento');
        $this->actingAs($this->usuario('admin'))->post(route('caja.movimiento', $turno), [
            'tipo' => 'EGRESO', 'concepto' => 'Pago al proveedor de hielo', 'monto' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $turno->movimientos()->where('concepto', 'Pago al proveedor de hielo')->count());
    }

    /** El cajero no ve un enlace al turno de otro que le daría 403. */
    public function test_el_cajero_no_ve_el_enlace_al_turno_de_otro(): void
    {
        $turnoAdmin = $this->turnoDe($this->usuario('admin'));

        $this->actingAs($this->usuario('cajero1'))->get(route('caja.index'))->assertOk()
            ->assertDontSee(route('caja.show', $turnoAdmin), false);
        $this->actingAs($this->usuario('admin'))->get(route('caja.index'))->assertOk()
            ->assertSee(route('caja.show', $turnoAdmin), false);
    }

    /** El almacenero ve los clientes pero no los botones de editar que le darían 403. */
    public function test_el_almacenero_no_ve_acciones_de_clientes_que_no_puede_hacer(): void
    {
        $this->actingAs($this->usuario('almacen'))->get(route('clientes.index'))->assertOk()
            ->assertDontSee('@click="nuevo()"', false)->assertDontSee('title="Eliminar"', false);
        $this->actingAs($this->usuario('admin'))->get(route('clientes.index'))->assertOk()
            ->assertSee('@click="nuevo()"', false);
    }

    /** Anular una venta cobrada por QR deja a la vista lo que hay que devolver por el banco. */
    public function test_anular_una_venta_cobrada_por_qr_muestra_el_reintegro(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turnoDe($cajero);
        $total = (float) Ventas::registrar(
            sesion: $turno->fresh(), usuario: $cajero,
            lineas: [['producto_id' => $this->comun(80)->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
        )->total;
        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, $total), $cajero);
        $venta = $this->venta($turno, $cajero, 1, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id]]);

        $this->actingAs($this->usuario('admin'))->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('hay que devolverlos por el banco');

        Ventas::anular($venta->fresh(), $this->usuario('admin'), 'Se equivocó de producto');

        $this->assertSame($total, $venta->fresh()->reintegro_por_anulacion);
        $this->actingAs($this->usuario('admin'))->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('data-reintegro-anulacion', false);
    }

    /** Un QR pagado sin venta se ve en la caja, y al cerrar los que esperaban pago se cancelan. */
    public function test_los_qr_pagados_sin_venta_se_ven_y_los_pendientes_se_cancelan_al_cerrar(): void
    {
        $cajero = $this->usuario('cajero1');
        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($cajero);

        $pagado = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, 12.5), $cajero);
        $pendiente = CobrosQr::generar($turno->fresh(), $cajero, 7);

        $this->actingAs($admin)->get(route('caja.show', $turno))->assertOk()
            ->assertSee('data-qr-sin-venta', false)->assertSee('1 cobro(s) por QR pagados sin venta');

        $turno = $turno->fresh();
        Cajas::cerrar($turno, $admin, $turno->efectivoEsperado(), null, 0, $turno->huella());

        $this->assertNotSame(CobroQr::PENDIENTE, $pendiente->fresh()->estado, 'un QR de un turno cerrado sigue cobrable');
        $this->assertSame(CobroQr::PAGADO, $pagado->fresh()->estado);
        $this->actingAs($admin)->get(route('caja.imprimir', $turno))->assertOk()->assertSee('data-qr-sin-venta', false);
    }

    /** Con la facturación oculta, sustituir un comprobante no existe, tampoco por un envío directo. */
    public function test_con_la_facturacion_oculta_no_se_sustituye_un_comprobante(): void
    {
        $admin = $this->usuario('admin');
        $venta = $this->venta($this->turnoDe($admin), $admin);

        config(['ventas.mostrar_facturacion' => false]);
        $this->actingAs($admin)->post(route('comprobantes.sustituir', $venta->comprobante), ['motivo' => 'Corregir el nombre'])
            ->assertNotFound();
        $this->assertSame('EMITIDO', $venta->comprobante->fresh()->estado);
    }

    /** La ficha del producto la abre quien trabaja con inventario o reportes, que es quien la enlaza. */
    public function test_la_ficha_del_producto_abre_para_quien_la_enlaza(): void
    {
        $permisos = Route::getRoutes()->getByName('productos.show')->middleware();
        $permiso = collect($permisos)->first(fn ($m) => str_starts_with($m, 'permiso:'));

        foreach (['productos.gestionar', 'inventario.ingresar', 'inventario.ajustar', 'reportes.ver'] as $codigo) {
            $this->assertStringContainsString($codigo, (string) $permiso);
        }

        $this->actingAs($this->usuario('almacen'))->get(route('productos.show', $this->comun()))->assertOk();
        $this->actingAs($this->usuario('cajero1'))->get(route('productos.show', $this->comun()))->assertForbidden();
        $this->actingAs($this->usuario('admin'))->get(route('productos.create'))->assertOk();
    }

    /** Si la copia a la carpeta externa falla, el respaldo nocturno no termina «bien» en silencio. */
    public function test_el_respaldo_nocturno_avisa_si_no_pudo_copiar_afuera(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'nodir');
        config(['ventas.respaldos.copia' => $archivo.DIRECTORY_SEPARATOR.'adentro']);

        try {
            $this->artisan('respaldo:crear')->assertFailed();
            $this->assertTrue(Auditoria::where('accion', 'RESPALDO_COPIA_FALLIDA')->exists());
        } finally {
            @unlink($archivo);
        }
    }

    /** Devolver una venta entera deja la ganancia como estaba: también el centavo que cuadra la devolución. */
    public function test_devolver_una_venta_entera_no_deja_centavos_de_ganancia(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();

        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($admin);
        $productos = Producto::activos()->where('controla_vencimiento', 0)
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->limit(2)->get();
        $productos[0]->forceFill(['precio_venta' => '3.54', 'stock_actual' => 50])->save();
        $productos[1]->forceFill(['precio_venta' => '2.48', 'stock_actual' => 50])->save();

        $ganancia = fn () => $this->actingAs($admin)->get(route('reportes.ventas'))->assertOk()->viewData('resumen')['ganancia'];
        $antes = $ganancia();

        $venta = Ventas::registrar(
            sesion: $turno->fresh(), usuario: $admin,
            lineas: [['producto_id' => $productos[0]->id, 'cantidad' => 2], ['producto_id' => $productos[1]->id, 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
            descuento: 0.37,
        );

        Devoluciones::registrar($venta->fresh(), $admin, $turno->fresh(),
            $venta->detalle->map(fn ($d) => ['venta_detalle_id' => $d->id, 'cantidad' => (float) $d->cantidad, 'reingresa_stock' => true])->all(),
            'Devuelve todo', Devolucion::EFECTIVO);

        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
        $this->assertSame($antes, $ganancia());
    }
}
