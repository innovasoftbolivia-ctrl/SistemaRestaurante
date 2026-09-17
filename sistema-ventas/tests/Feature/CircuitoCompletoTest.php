<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\Devolucion;
use App\Models\DevolucionCompra;
use App\Models\Lote;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Compras;
use App\Services\Devoluciones;
use App\Services\DevolucionesCompra;
use App\Services\Inventario;
use App\Services\TomasInventario;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Un día entero del local, módulo tras módulo, y al final las cuentas que
 * tienen que cuadrar ENTRE módulos: el stock con el kardex y con los lotes, lo
 * devuelto con lo vendido, el arqueo con los pagos, el reporte con las ventas,
 * los comprobantes con las ventas. Corre igual en los dos modos (procedimientos
 * y LOGICA_EN_PHP).
 */
class CircuitoCompletoTest extends TestCase
{
    use DatabaseTransactions;

    private int $desdeMovimiento = 0;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    /** Un producto por unidad, con control de vencimiento, sin stock ni lotes previos. */
    private function perecedero(): Producto
    {
        $p = Producto::activos()->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        Lote::where('producto_id', $p->id)->delete();
        $p->forceFill(['controla_vencimiento' => 1, 'contenido_empaque' => null, 'nombre_empaque' => null, 'stock_actual' => 0])->save();

        return $p->fresh();
    }

    private function porPeso(): Producto
    {
        $p = Producto::activos()->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 1))->orderBy('id')->firstOrFail();
        $p->forceFill(['stock_actual' => 40])->save();

        return $p->fresh();
    }

    private function comun(array $excluir): Producto
    {
        $p = Producto::activos()->whereNotIn('id', $excluir)->where('controla_vencimiento', 0)
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        $p->forceFill(['stock_actual' => 50, 'contenido_empaque' => null, 'nombre_empaque' => null])->save();

        return $p->fresh();
    }

    /** @param  array<int, array{0: Producto, 1: float}>  $lineas */
    private function vender(SesionCaja $sesion, Usuario $usuario, array $lineas, array $pagos, float $descuento = 0): Venta
    {
        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $usuario,
            lineas: array_map(fn ($l) => ['producto_id' => $l[0]->id, 'cantidad' => $l[1]], $lineas),
            pagos: $pagos,
            descuento: $descuento,
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function regimenes(): array
    {
        return [
            'sin IVA (como trabaja hoy)' => ['0.0000', '1'],
            'IVA 13 % incluido en el precio' => ['0.1300', '1'],
            'IVA 13 % sumado al cobrar' => ['0.1300', '0'],
        ];
    }

    #[DataProvider('regimenes')]
    public function test_un_dia_completo_cuadra_entre_todos_los_modulos(string $tasa, string $incluido): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');
        $almacen = $this->usuario('almacen');

        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => $tasa]);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => $incluido]);
        Config::olvidar();

        $leche = $this->perecedero();
        $queso = $this->porPeso();
        $arroz = $this->comun([$leche->id, $queso->id]);
        $productos = [$leche, $queso, $arroz];
        $this->desdeMovimiento = (int) DB::table('movimientos_inventario')->max('id');

        $resumenAntes = $this->actingAs($admin)->get(route('reportes.ventas'))->assertOk()->viewData('resumen');

        // ------------------------------------------------------------ compra
        $this->actingAs($almacen);
        $compra = Compras::registrar($almacen, Proveedor::firstOrFail(), [
            ['producto_id' => $leche->id, 'cantidad' => 20, 'costo_unitario' => 5, 'vence' => now()->addDays(30)->toDateString(), 'lote' => 'L-LARGO'],
            ['producto_id' => $leche->id, 'cantidad' => 10, 'costo_unitario' => 5, 'vence' => now()->addDays(5)->toDateString(), 'lote' => 'L-CORTO'],
            ['producto_id' => $arroz->id, 'cantidad' => 15, 'costo_unitario' => 3],
        ], 'F-0001');
        $this->assertSame(30.0, (float) $leche->fresh()->stock_actual);

        // ------------------------------------------------------------ caja
        $turno = Cajas::abrir(Caja::firstOrFail(), $cajero, 100);

        // Venta 1: efectivo con vuelto. Sale primero lo que vence antes.
        $precioLeche = (float) $leche->fresh()->precio_venta;
        $v1 = $this->vender($turno, $cajero, [[$leche, 12], [$queso, 1.25], [$arroz, 3]], [
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null, 'monto_recibido' => null],
        ]);
        $this->assertSame(0.0, (float) Lote::where('producto_id', $leche->id)->where('codigo', 'L-CORTO')->value('cantidad_actual'));
        $this->assertSame(18.0, (float) Lote::where('producto_id', $leche->id)->where('codigo', 'L-LARGO')->value('cantidad_actual'));

        // Venta 2: mitad efectivo, mitad QR.
        // Lo que cobrará la venta, con la misma cuenta que el mostrador.
        $leche = $leche->fresh();
        $importe = round($precioLeche * 4, 2);
        $total2 = $incluido === '1' || ! $leche->afecto_impuesto ? $importe : round($importe + round($importe * (float) $tasa, 2), 2);
        $mitadQr = round($total2 / 2, 2);
        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno->fresh(), $cajero, $mitadQr), $cajero);
        $v2 = $this->vender($turno, $cajero, [[$leche, 4]], [
            ['metodo_pago_id' => $this->metodo('QR'), 'monto' => $mitadQr, 'cobro_qr_id' => $cobro->id],
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null],
        ]);

        // Venta 3: con descuento, después anulada.
        $v3 = $this->vender($turno, $cajero, [[$arroz, 2]], [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]], 0.5);

        // Movimientos de caja.
        Cajas::movimiento($turno->fresh(), $cajero, 'INGRESO', 'Cambio del banco', 50);
        Cajas::movimiento($turno->fresh(), $cajero, 'EGRESO', 'Bolsas', 30);

        // ------------------------------------------------------------ devoluciones y anulación
        $d1 = Devoluciones::registrar($v1->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $v1->detalle->firstWhere('producto_id', $leche->id)->id, 'cantidad' => 3, 'reingresa_stock' => true],
            ['venta_detalle_id' => $v1->detalle->firstWhere('producto_id', $arroz->id)->id, 'cantidad' => 1, 'reingresa_stock' => false],
        ], 'Cliente devolvió', Devolucion::EFECTIVO);

        $d2 = Devoluciones::registrar($v2->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $v2->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'Una en mal estado', Devolucion::MISMO_MEDIO);

        Ventas::anular($v3->fresh(), $admin, 'Error de digitación');

        // Una venta con devolución ya no se anula: se deshace por devoluciones.
        $this->assertThrows(fn () => Ventas::anular($v1->fresh(), $admin, 'No'), RuntimeException::class);
        // No se devuelve más de lo vendido.
        $this->assertThrows(fn () => Devoluciones::registrar($v1->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $v1->detalle->firstWhere('producto_id', $leche->id)->id, 'cantidad' => 10, 'reingresa_stock' => true],
        ], 'De más', Devolucion::EFECTIVO), RuntimeException::class);

        // ------------------------------------------------------------ almacén
        $this->actingAs($almacen);
        Inventario::ajuste($arroz->fresh(), (float) $arroz->fresh()->stock_actual - 2, 'Rotura');

        $detalleLargo = $compra->detalle()->where('producto_id', $leche->id)->orderBy('id')->firstOrFail();
        $devCompra = DevolucionesCompra::registrar($almacen, $compra->fresh(), [
            ['compra_detalle_id' => $detalleLargo->id, 'cantidad' => 2, 'lote_id' => Lote::where('producto_id', $leche->id)->where('codigo', 'L-LARGO')->value('id')],
        ], DevolucionCompra::MOTIVOS[0], 'PENDIENTE', 'NC-1');
        DevolucionesCompra::reponer($almacen, $devCompra->fresh(), [
            ['linea_id' => $devCompra->detalle()->firstOrFail()->id, 'cantidad' => 2, 'vence' => now()->addDays(60)->toDateString()],
        ], 'REP-1');

        // Toma de inventario con una venta entre el conteo y el cierre.
        $toma = TomasInventario::abrir($almacen);
        $lineaLeche = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $leche->id)->firstOrFail();
        $stockAlContar = (float) $leche->fresh()->stock_actual;
        TomasInventario::contar($lineaLeche, $almacen, $stockAlContar - 1);

        $v4 = $this->vender($turno, $cajero, [[$leche, 2]], [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]);
        $this->actingAs($almacen);
        TomasInventario::cerrar($toma->fresh(), $almacen);
        $this->assertSame($stockAlContar - 1 - 2, (float) $leche->fresh()->stock_actual, 'la toma se comió la venta hecha después de contar');

        // ------------------------------------------------------------ cierre
        $turno = $turno->fresh();
        $esperado = $this->efectivoEsperadoIndependiente($turno);
        $this->assertSame($esperado, round($turno->efectivoEsperado(), 2), 'la pantalla de caja no coincide con los datos');

        Cajas::cerrar($turno, $admin, $esperado, null, 20, $turno->huella());
        $turno = $turno->fresh();
        $this->assertSame($esperado, (float) $turno->monto_esperado, 'el cierre firmó otro esperado');
        $this->assertSame(0.0, (float) $turno->diferencia);

        // ------------------------------------------------------------ invariantes entre módulos
        foreach ($productos as $p) {
            $this->assertKardexEncadenado($p->fresh());
        }
        $this->assertSame((float) $leche->fresh()->stock_actual, (float) Lote::where('producto_id', $leche->id)->sum('cantidad_actual'), 'los lotes no suman el stock');

        foreach ([$v1, $v2, $v3, $v4] as $venta) {
            $this->assertVentaConsistente($venta->fresh());
        }

        $this->assertSame('ANULADA', $v3->fresh()->estado);
        $this->assertSame('DEVUELTA_PARCIAL', $v1->fresh()->estado);
        $this->assertSame(round((float) $d1->total, 2), (float) $v1->fresh()->total_devuelto);
        $this->assertSame(round((float) $d1->total, 2), (float) $d1->efectivo, 'reembolso en efectivo: sale entero del cajón');
        $this->assertLessThan((float) $d2->total, (float) $d2->efectivo, 'mismo medio: solo la parte que entró en efectivo');

        // El arroz roto no volvió al estante; la leche sí.
        $this->assertSame(0, DB::table('movimientos_inventario')->where('devolucion_id', $d1->id)->where('producto_id', $arroz->id)->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->where('devolucion_id', $d1->id)->where('producto_id', $leche->id)->count());

        // El reporte de ventas coincide con lo registrado.
        $resumen = $this->actingAs($admin)->get(route('reportes.ventas'))->assertOk()->viewData('resumen');
        $vendidoHoy = round((float) $v1->fresh()->total + (float) $v2->fresh()->total + (float) $v4->fresh()->total, 2);
        $devueltoHoy = round((float) $d1->total + (float) $d2->total, 2);
        $this->assertSame($vendidoHoy, round($resumen['vendido'] - $resumenAntes['vendido'], 2), 'vendido del reporte');
        $this->assertSame($devueltoHoy, round($resumen['devuelto'] - $resumenAntes['devuelto'], 2), 'devuelto del reporte');
        $this->assertSame(
            round($esperado - 100, 2),
            round($resumen['efectivo'] - $resumenAntes['efectivo'], 2),
            '«efectivo en cajas» del reporte no cuadra con el arqueo',
        );

        // Cada pantalla que muestra estos datos abre para quien puede verla.
        $pantallas = [
            'admin' => [
                route('ventas.show', $v1), route('ventas.show', $v3), route('ventas.index'),
                route('devoluciones.show', $d1), route('devoluciones.show', $d2),
                route('caja.show', $turno), route('comprobantes.index'),
                route('comprobantes.imprimir', $v1->fresh()->comprobante),
                route('reportes.ventas'), route('reportes.productos'), route('inicio'),
                route('reportes.ventas.excel'), route('reportes.productos.excel'),
                route('reportes.ventas.pdf'), route('reportes.productos.pdf'),
                route('tomas.show', $toma), route('compras.show', $compra),
                route('devoluciones-compra.show', $devCompra),
                route('productos.show', $leche), route('bitacora.index'),
            ],
            'cajero1' => [route('ventas.show', $v1), route('pos.index'), route('caja.show', $turno), route('comprobantes.imprimir', $v2->fresh()->comprobante)],
            'almacen' => [route('productos.show', $leche), route('tomas.show', $toma), route('compras.show', $compra)],
        ];

        foreach ($pantallas as $quien => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($this->usuario($quien))->get($url)->assertSuccessful();
            }
        }
    }

    /** El efectivo esperado calculado aparte, a partir de las tablas crudas. */
    private function efectivoEsperadoIndependiente(SesionCaja $sesion): float
    {
        $ventas = (float) DB::table('venta_pagos as p')
            ->join('ventas as v', 'v.id', '=', 'p.venta_id')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->where('v.sesion_caja_id', $sesion->id)->where('v.estado', '<>', 'ANULADA')->where('m.codigo', 'EFECTIVO')
            ->sum('p.monto');
        $ingresos = (float) DB::table('movimientos_caja')->where('sesion_caja_id', $sesion->id)->where('tipo', 'INGRESO')->sum('monto');
        $egresos = (float) DB::table('movimientos_caja')->where('sesion_caja_id', $sesion->id)->where('tipo', 'EGRESO')->sum('monto');
        $devuelto = (float) DB::table('devoluciones')->where('sesion_caja_id', $sesion->id)->sum('efectivo');

        return round((float) $sesion->monto_inicial + $ventas + $ingresos - $egresos - $devuelto, 2);
    }

    private function assertKardexEncadenado(Producto $producto): void
    {
        $movimientos = DB::table('movimientos_inventario')->where('producto_id', $producto->id)
            ->where('id', '>', $this->desdeMovimiento)->orderBy('id')->get();
        $anterior = null;

        foreach ($movimientos as $m) {
            if ($anterior !== null) {
                $this->assertSame($anterior, (float) $m->stock_anterior, "kardex de «{$producto->nombre}» cortado en el movimiento {$m->id} ({$m->origen})");
            }

            $esperado = match ($m->tipo) {
                'ENTRADA' => round((float) $m->stock_anterior + (float) $m->cantidad, 3),
                'SALIDA' => round((float) $m->stock_anterior - (float) $m->cantidad, 3),
                default => (float) $m->stock_resultante,
            };
            $this->assertSame($esperado, (float) $m->stock_resultante, "movimiento {$m->id} ({$m->tipo} {$m->origen}) no suma bien");

            if ($m->tipo === 'AJUSTE') {
                $this->assertSame(round(abs((float) $m->stock_resultante - (float) $m->stock_anterior), 3), (float) $m->cantidad);
            }

            $this->assertTrue($m->usuario_id !== null || $m->origen === 'INICIAL', "movimiento {$m->id} sin responsable");
            $anterior = (float) $m->stock_resultante;
        }

        // Los productos de la prueba arrancaron con stock puesto a mano: se
        // compara desde el primer movimiento de hoy.
        $this->assertSame($anterior ?? (float) $producto->stock_actual, (float) $producto->stock_actual, "el stock de «{$producto->nombre}» no es el del último movimiento");
    }

    private function assertVentaConsistente(Venta $venta): void
    {
        $pagado = round((float) $venta->pagos()->sum('monto'), 2);
        $this->assertSame((float) $venta->total, $pagado, "venta {$venta->id}: lo pagado no suma el total");

        // Con el IVA incluido, el descuento se resta del precio final. Con el
        // IVA encima, se resta de la base y el impuesto baja en proporción.
        if ($venta->impuesto_incluido) {
            $lineas = round((float) $venta->detalle()->sum('total_linea'), 2);
            $esperado = round($lineas - (float) ($venta->descuento_precio_final ?? 0), 2);
        } else {
            $base = round((float) $venta->detalle()->sum('importe'), 2);
            $impuesto = round((float) $venta->detalle()->sum('impuesto_linea'), 2);
            $descuento = (float) $venta->descuento;
            $impuestoFinal = $base > 0 ? round($impuesto * ($base - $descuento) / $base, 2) : 0.0;
            $esperado = round($base - $descuento + $impuestoFinal, 2);
            $this->assertSame($base, (float) $venta->subtotal, "venta {$venta->id}: subtotal");
        }
        $this->assertSame($esperado, (float) $venta->total, "venta {$venta->id}: las líneas y el descuento no dan el total");

        foreach ($venta->detalle as $d) {
            $devuelto = (float) DB::table('devolucion_detalle')->where('venta_detalle_id', $d->id)->sum('cantidad');
            $this->assertSame($devuelto, (float) $d->cantidad_devuelta, "línea {$d->id}: cantidad_devuelta no es lo devuelto");
        }

        $this->assertSame(
            round((float) DB::table('devoluciones')->where('venta_id', $venta->id)->sum('total'), 2),
            (float) $venta->total_devuelto,
            "venta {$venta->id}: total_devuelto",
        );

        // Una venta válida tiene exactamente un comprobante vigente; una
        // anulada, ninguno, y el suyo queda ANULADO (no se borra).
        $vigentes = DB::table('comprobantes')->where('venta_id', $venta->id)->where('estado', 'EMITIDO')->count();
        $this->assertSame($venta->estado === 'ANULADA' ? 0 : 1, $vigentes, "venta {$venta->id} ({$venta->estado}): comprobantes vigentes");
        if ($venta->estado === 'ANULADA') {
            $this->assertSame(1, DB::table('comprobantes')->where('venta_id', $venta->id)->where('estado', 'ANULADO')->count(), "venta {$venta->id}: comprobante anulado");
        }

        // Stock: lo que salió por la venta menos lo que volvió por anulación.
        $salio = (float) DB::table('movimientos_inventario')->where('venta_id', $venta->id)->where('origen', 'VENTA')->sum('cantidad');
        $volvioAnulacion = (float) DB::table('movimientos_inventario')->where('venta_id', $venta->id)->where('origen', 'ANULACION')->sum('cantidad');
        $this->assertSame(round((float) $venta->detalle()->sum('cantidad'), 3), round($salio, 3), "venta {$venta->id}: el kardex no descontó lo vendido");
        $this->assertSame($venta->estado === 'ANULADA' ? round($salio, 3) : 0.0, round($volvioAnulacion, 3), "venta {$venta->id}: reposición por anulación");
    }
}
