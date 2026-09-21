<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Mensaje;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Lo que encontró la revisión del 20/09 (lógica, arquitectura y base), una
 * prueba por hallazgo arreglado.
 */
class RevisionDelVeinteTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        $usuario ??= $this->usuario('cajero1');

        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100))->fresh();
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    private function venderEnMostrador(Producto $producto, ?SesionCaja $turno = null): Venta
    {
        $turno ??= $this->turno();

        $this->actingAs($this->usuario('cajero1'))->post(route('pos.store'), [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()]],
        ])->assertSessionHasNoErrors();

        return Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
    }

    /**
     * Un plato que salió del menú después de cobrarse no impide volver a
     * cobrar su pedido cuando se anula la venta: se validó al pedirlo.
     */
    public function test_un_pedido_reabierto_se_vuelve_a_cobrar_aunque_el_plato_saliera_del_menu(): void
    {
        $turno = $this->turno();
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        $venta = $this->venderEnMostrador($plato, $turno);
        $pedido = $venta->pedido;

        Ventas::anular($venta, $this->usuario('admin'), 'Forma de pago equivocada');
        $plato->update(['activo' => 0]);

        $nueva = Pedidos::cobrar($pedido->fresh(), $turno->fresh(), $this->usuario('cajero1'), [
            ['metodo_pago_id' => $this->efectivo(), 'monto' => null],
        ]);

        $this->assertSame('COMPLETADA', $nueva->estado);
        $this->assertSame($pedido->id, $nueva->pedido_id);
    }

    /** Pero una venta nueva sigue sin poder llevar un plato retirado. */
    public function test_una_venta_nueva_no_lleva_un_plato_retirado(): void
    {
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        $plato->update(['activo' => 0]);

        $this->actingAs($this->usuario('cajero1'))->post(route('pos.store'), [
            'lineas' => [['producto_id' => $plato->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()]],
        ]);

        $this->assertSame(0, Venta::where('sesion_caja_id', $this->turno()->id)->count());
    }

    /**
     * El cajero no averigua el efectivo esperado con un egreso enorme: el
     * tope suyo se revisa primero, y el monto esperado solo lo ve quien arquea.
     */
    public function test_el_cajero_no_averigua_el_efectivo_esperado_con_un_egreso(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($cajero);

        $mensaje = fn (Usuario $quien) => rescue(fn () => Cajas::movimiento($turno->fresh(), $quien, 'EGRESO', 'Prueba', 9_999_999), fn ($e) => $e->getMessage(), false);

        $delCajero = $mensaje($cajero);
        $this->assertStringContainsString('Tu rol puede sacar', $delCajero);
        $this->assertStringNotContainsString('debería haber en el cajón (', $delCajero);

        $delAdmin = $mensaje($this->usuario('admin'));
        $this->assertStringContainsString('debería haber en el cajón (', $delAdmin);
    }

    /** La ruta pide lo mismo que el controlador: abrir caja, o poder cerrarla. */
    public function test_la_ruta_de_movimientos_acepta_a_quien_cierra_caja(): void
    {
        $this->assertContains('permiso:caja.abrir,caja.cerrar', Route::getRoutes()->getByName('caja.movimiento')->gatherMiddleware());
    }

    /** La cocina solo mueve lo que está en su pantalla: no las bebidas. */
    public function test_la_cocina_no_mueve_lo_que_no_esta_en_su_pantalla(): void
    {
        $venta = $this->venderEnMostrador(Producto::where('codigo', 'P-0005')->firstOrFail());
        $bebida = $venta->pedido->detalle()->firstOrFail();
        $this->assertFalse((bool) $bebida->pasa_por_cocina);
        $antes = $bebida->estado_cocina;

        $this->actingAs($this->usuario('cocina1'))
            ->post(route('cocina.estado', $bebida), ['estado' => PedidoDetalle::LISTO])
            ->assertSessionHas('error');

        $this->assertSame($antes, $bebida->fresh()->estado_cocina);
    }

    /**
     * Un QR se paga una sola vez y no se anula ya pagado, aunque quien llega
     * segundo tenga el cobro leído de antes (el aviso del banco y el sondeo a
     * la vez).
     */
    public function test_un_qr_pagado_no_se_paga_dos_veces_ni_se_anula(): void
    {
        $cajero = $this->usuario('cajero1');
        $cobro = CobrosQr::generar($this->turno($cajero), $cajero, 10.00);
        $viejo = CobroQr::findOrFail($cobro->id);

        CobrosQr::confirmarAMano($cobro, $cajero);
        CobrosQr::confirmarAMano($viejo, $cajero);

        $this->assertSame(1, Auditoria::where('accion', 'QR_PAGADO')->where('entidad_id', $cobro->id)->count());

        $this->assertThrows(fn () => CobrosQr::anular($viejo, $cajero), RuntimeException::class, 'ya pagó');
        $this->assertSame(CobroQr::PAGADO, $cobro->fresh()->estado);
    }

    /** «Sin documento» no lleva número: lo rechaza el formulario, con su mensaje. */
    public function test_sin_documento_no_lleva_numero(): void
    {
        $this->actingAs($this->usuario('admin'))->post(route('clientes.store'), [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'SIN', 'documento' => 'ABC1234',
            'nombres' => 'Ana', 'apellidos' => 'Rojas',
        ])->assertSessionHasErrors('documento');
    }

    /** Un `findOrFail` dentro de un servicio no muestra el nombre del modelo. */
    public function test_el_mensaje_no_muestra_el_modelo_que_no_se_encontro(): void
    {
        $texto = Mensaje::de((new ModelNotFoundException)->setModel(Producto::class, [99]));

        $this->assertStringNotContainsString('App\\Models', $texto);
        $this->assertStringNotContainsString('No query results', $texto);
    }

    /**
     * Las dos vistas de ventas cortan el día igual, por jornada: una venta de
     * las 02:00 es de la noche anterior en las dos.
     */
    public function test_el_reporte_por_forma_de_pago_corta_por_jornada(): void
    {
        $venta = $this->venderEnMostrador(Producto::where('codigo', 'P-0004')->firstOrFail());
        DB::table('ventas')->where('id', $venta->id)->update(['fecha' => now()->startOfDay()->addHours(2)]);

        $porDia = DB::table('v_ventas_por_dia')->pluck('monto_total', 'dia')->map(fn ($m) => round((float) $m, 2));
        $porMetodo = DB::table('v_ventas_por_metodo_pago')
            ->selectRaw('dia, SUM(monto) AS monto')->groupBy('dia')
            ->pluck('monto', 'dia')->map(fn ($m) => round((float) $m, 2));

        $this->assertEquals($porDia->sortKeys()->all(), $porMetodo->sortKeys()->all());
    }
}
