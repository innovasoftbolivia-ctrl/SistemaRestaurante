<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un día entero del restaurante, de la apertura de caja al arqueo, como
 * trabaja el local: todo se pide y se paga en el mostrador, el cliente se
 * lleva su ticket con el número, la cocina prepara y quien lleva los platos
 * canta el número. Al final, las cuentas que tienen que cuadrar ENTRE
 * módulos: lo que el mostrador le cantó a cada cliente con lo que cobró la
 * venta, el arqueo con los pagos, el reporte con las ventas, los comprobantes
 * con las ventas, y la cocina vacía cuando todo se entregó. Corre igual en los
 * dos modos (procedimientos de la base y LOGICA_EN_PHP) y con los tres
 * regímenes de impuesto.
 */
class CircuitoCompletoTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    private function plato(string $codigo): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    /** La cocina lleva un plato hasta el cliente, tocando sus botones. */
    private function servir(PedidoDetalle $linea): void
    {
        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            $this->actingAs($this->usuario('cocina1'))
                ->post(route('cocina.estado', $linea), ['estado' => $paso])
                ->assertSessionHas('exito');
        }
    }

    /** @return array<int, int> los platos que la pantalla de la cocina tiene delante */
    private function enLaCocina(): array
    {
        return collect($this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))->assertOk()->json('tandas'))
            ->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))
            ->all();
    }

    /**
     * Lo que el mostrador le canta al cliente: el total que calcula la
     * pantalla, línea por línea con su impuesto, igual que la venta.
     *
     * @param  array<int, array{0: Producto, 1: int}>  $lineas
     */
    private function cantado(array $lineas): float
    {
        $incluido = Config::preciosIncluyenImpuesto();
        $tasa = Config::tasaImpuesto();
        $total = 0.0;

        foreach ($lineas as [$plato, $cantidad]) {
            $importe = round((float) $plato->precio_venta * $cantidad, 2);
            $total += $incluido || ! $plato->afecto_impuesto ? $importe : $importe + round($importe * $tasa, 2);
        }

        return round($total, 2);
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
    public function test_un_dia_del_restaurante_cuadra_entre_todos_los_modulos(string $tasa, string $incluido): void
    {
        $admin = $this->usuario('admin');
        $cajero = $this->usuario('cajero1');

        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => $tasa]);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => $incluido]);
        Config::olvidar();
        Pedidos::olvidar();

        $pollo = $this->plato('P-0001');
        $jugo = $this->plato('P-0002');       // Bebidas: se cobra, no va a la cocina
        $milanesa = $this->plato('P-0004');
        $helado = $this->plato('P-0008');

        $resumenAntes = $this->actingAs($admin)->get(route('reportes.ventas'))->assertOk()->viewData('resumen');

        // ------------------------------------------------------ abre la caja
        $turno = Cajas::abrir(Caja::firstOrFail(), $cajero, 100);

        // ------------------- una mesa de dos pide en el mostrador, en efectivo
        $cantadoLocal = $this->cantado([[$pollo, 2], [$jugo, 2]]);
        $this->actingAs($cajero)->post(route('pos.store'), [
            'tipo' => Pedido::LOCAL,
            'lineas' => [
                ['producto_id' => $pollo->id, 'cantidad' => 2, 'nota' => 'uno sin ensalada'],
                ['producto_id' => $jugo->id, 'cantidad' => 2],
            ],
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto_recibido' => '200.00']],
            'total_esperado' => number_format($cantadoLocal, 2, '.', ''),
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');
        $ventaLocal = Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
        $local = $ventaLocal->pedido;

        // El pollo va a la cocina con su nota; el jugo no, pero se cobró igual.
        $delPollo = $local->detalle()->where('producto_id', $pollo->id)->firstOrFail();
        $this->assertContains($delPollo->id, $this->enLaCocina());
        $this->assertNotContains($local->detalle()->where('producto_id', $jugo->id)->value('id'), $this->enLaCocina());

        // ------------------------ un pedido para llevar, pagado por QR
        $cantadoLlevar = $this->cantado([[$milanesa, 2]]);
        $qr = CobrosQr::confirmarAMano(CobrosQr::generar($turno->fresh(), $cajero, $cantadoLlevar), $cajero);
        $this->actingAs($cajero)->post(route('pos.store'), [
            'tipo' => Pedido::LLEVAR,
            'nombre_cliente' => 'Ana',
            'lineas' => [['producto_id' => $milanesa->id, 'cantidad' => 2, 'nota' => 'sin arroz']],
            'pagos' => [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $qr->id]],
            'total_esperado' => number_format($cantadoLlevar, 2, '.', ''),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $ventaLlevar = Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
        $llevar = $ventaLlevar->pedido;
        $this->assertSame($local->numero_dia + 1, $llevar->numero_dia, 'un solo contador para comer aquí y para llevar');

        // Cobrado no es servido: sigue en la cocina hasta que sale.
        $milanesaLlevar = $llevar->detalle()->firstOrFail();
        $this->assertContains($milanesaLlevar->id, $this->enLaCocina());

        // -------------- el postre de otra mesa, cobrado con la forma equivocada
        $cantadoPostre = $this->cantado([[$helado, 2]]);
        $this->actingAs($cajero)->post(route('pos.store'), [
            'lineas' => [['producto_id' => $helado->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
            'total_esperado' => number_format($cantadoPostre, 2, '.', ''),
        ])->assertSessionHasNoErrors();
        $equivocada = Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
        $postre = $equivocada->pedido;

        // Pagaban mitad con tarjeta: se anula y el pedido queda para volver a
        // cobrar, con su número y en la cocina.
        $this->actingAs($admin)->post(route('ventas.anular', $equivocada), [
            'motivo_anulacion' => 'Se cobró todo en efectivo y pagaban mitad con tarjeta',
        ])->assertSessionHas('exito', fn (string $m) => str_contains($m, 'queda para volver a cobrar'));
        $this->assertTrue($postre->fresh()->estaAbierto());
        $this->assertContains($postre->detalle()->value('id'), $this->enLaCocina());
        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()->assertSee($postre->fresh()->etiqueta);

        // Y un tercero, también anulado, que el cliente ya no quiere: se cancela.
        $this->actingAs($cajero)->post(route('pos.store'), [
            'lineas' => [['producto_id' => $pollo->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ])->assertSessionHasNoErrors();
        $plantada = Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
        $plantado = $plantada->pedido;
        $this->actingAs($admin)->post(route('ventas.anular', $plantada), ['motivo_anulacion' => 'El cliente se arrepintió'])
            ->assertSessionHas('exito');
        $this->actingAs($cajero)->post(route('pedidos.cancelar', $plantado), ['motivo' => 'Se fue antes de que empezara la cocina'])
            ->assertRedirect(route('pos.index'));
        $this->assertNotContains($plantado->detalle()->value('id'), $this->enLaCocina());

        // ------------------------------------------ la cocina hace lo suyo
        $this->servir($delPollo);
        $this->servir($milanesaLlevar);

        // ------------------------------ el postre se cobra de nuevo, bien
        $cantadoPostre = Pedidos::totalesDe($postre->fresh())['total'];
        $conTarjeta = round($cantadoPostre / 2, 2);

        $this->actingAs($cajero)->post(route('pedidos.cobrar.store', $postre), [
            '_envio' => 'circuito-cobro-postre',
            'total_esperado' => number_format($cantadoPostre, 2, '.', ''),
            'pagos' => [
                ['metodo_pago_id' => $this->metodo('TARJETA'), 'monto' => number_format($conTarjeta, 2, '.', ''), 'referencia' => 'VOUCHER-4471'],
                ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto_recibido' => '200.00'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');
        $ventaPostre = $postre->fresh()->venta;
        $this->assertSame($postre->numero_dia, $postre->fresh()->numero_dia, 'el pedido cobrado de nuevo cambió de número');

        // El postre sigue en la cocina hasta servirse, y después no queda nada.
        $this->assertContains($postre->detalle()->value('id'), $this->enLaCocina());
        $this->servir($postre->detalle()->firstOrFail());
        $this->assertSame([], array_intersect(
            [$delPollo->id, $milanesaLlevar->id, ...$postre->detalle()->pluck('id')],
            $this->enLaCocina(),
        ));

        // ------------------------------------------------ movimientos de caja
        Cajas::movimiento($turno->fresh(), $cajero, 'INGRESO', 'Cambio del banco', 50);
        Cajas::movimiento($turno->fresh(), $cajero, 'EGRESO', 'Compra de hielo', 30);

        // ------------------------------------------------------------ cierre
        $turno = $turno->fresh();
        $esperado = $this->efectivoEsperadoIndependiente($turno);
        $this->assertSame($esperado, round($turno->efectivoEsperado(), 2), 'la pantalla de caja no coincide con los datos');

        // Todo cobrado o cancelado: el cierre no tiene cobros anulados que confirmar.
        $this->assertSame(0, Cajas::cuentasAbiertas()->whereIn('id', [$local->id, $llevar->id, $postre->id, $plantado->id])->count());
        $this->actingAs($admin)->post(route('caja.cerrar', $turno), [
            'monto_declarado' => number_format($esperado, 2, '.', ''), 'fondo_dejado' => 20, 'huella' => $turno->huella(),
        ])->assertRedirect(route('caja.imprimir', $turno));

        $turno = $turno->fresh();
        $this->assertSame($esperado, (float) $turno->monto_esperado, 'el cierre firmó otro esperado');
        $this->assertSame(0.0, (float) $turno->diferencia);

        // ------------------------------------------ invariantes entre módulos
        foreach ([$ventaLocal, $ventaLlevar, $equivocada, $ventaPostre, $plantada] as $venta) {
            $this->assertVentaConsistente($venta->fresh());
        }

        // Lo que el mostrador cantó es lo que la venta cobró.
        $this->assertSame($cantadoLocal, (float) $ventaLocal->fresh()->total);
        $this->assertSame($cantadoLlevar, (float) $ventaLlevar->fresh()->total);
        $this->assertSame($cantadoPostre, (float) $ventaPostre->fresh()->total);

        // Cada pedido quedó cerrado con su venta válida; el cancelado, sin venta.
        $this->assertSame([Pedido::CERRADO, $ventaLocal->id], [$local->fresh()->estado, $local->fresh()->venta?->id]);
        $this->assertSame([Pedido::CERRADO, $ventaLlevar->id], [$llevar->fresh()->estado, $llevar->fresh()->venta?->id]);
        $this->assertSame([Pedido::CERRADO, $ventaPostre->id], [$postre->fresh()->estado, $postre->fresh()->venta?->id]);
        $this->assertSame([Pedido::CANCELADO, null], [$plantado->fresh()->estado, $plantado->fresh()->venta?->id]);
        // Y las anuladas siguen diciendo de qué pedido eran.
        $this->assertSame($postre->id, (int) $equivocada->fresh()->pedido_id);
        $this->assertSame(['ANULADA', 'ANULADA'], [$equivocada->fresh()->estado, $plantada->fresh()->estado]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_REABIERTO', 'entidad_id' => $postre->id]);

        // El reporte de ventas coincide con lo registrado: las anuladas no suman.
        $resumen = $this->actingAs($admin)->get(route('reportes.ventas'))->assertOk()->viewData('resumen');
        $vendidoHoy = round((float) $ventaLocal->fresh()->total + (float) $ventaLlevar->fresh()->total + (float) $ventaPostre->fresh()->total, 2);
        $this->assertSame($vendidoHoy, round($resumen['vendido'] - $resumenAntes['vendido'], 2), 'vendido del reporte');
        $this->assertSame(2, $resumen['anuladas'] - $resumenAntes['anuladas'], 'anuladas del reporte');
        $this->assertSame(
            round($esperado - 100, 2),
            round($resumen['efectivo'] - $resumenAntes['efectivo'], 2),
            '«efectivo en cajas» del reporte no cuadra con el arqueo',
        );

        // Cada pantalla que muestra estos datos abre para quien puede verla.
        $pantallas = [
            'admin' => [
                route('ventas.show', $ventaPostre), route('ventas.show', $equivocada), route('ventas.index'),
                route('caja.show', $turno), route('caja.imprimir', $turno), route('comprobantes.index'),
                route('comprobantes.imprimir', $ventaPostre->fresh()->comprobante),
                route('reportes.ventas'), route('reportes.productos'), route('inicio'),
                route('reportes.ventas.excel'), route('reportes.productos.excel'),
                route('reportes.ventas.pdf'), route('reportes.productos.pdf'),
                route('productos.show', $pollo), route('bitacora.index'), route('cocina.index'),
            ],
            'cajero1' => [
                route('ventas.show', $ventaLlevar), route('pos.index'),
                route('caja.show', $turno), route('comprobantes.imprimir', $ventaLlevar->fresh()->comprobante),
            ],
            'cocina1' => [route('cocina.index'), route('perfil.edit')],
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

        return round((float) $sesion->monto_inicial + $ventas + $ingresos - $egresos, 2);
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

        // Una venta válida tiene exactamente un comprobante vigente; una
        // anulada, ninguno, y el suyo queda ANULADO (no se borra).
        $vigentes = DB::table('comprobantes')->where('venta_id', $venta->id)->where('estado', 'EMITIDO')->count();
        $this->assertSame($venta->estado === 'ANULADA' ? 0 : 1, $vigentes, "venta {$venta->id} ({$venta->estado}): comprobantes vigentes");
        if ($venta->estado === 'ANULADA') {
            $this->assertSame(1, DB::table('comprobantes')->where('venta_id', $venta->id)->where('estado', 'ANULADO')->count(), "venta {$venta->id}: comprobante anulado");
        }
    }
}
