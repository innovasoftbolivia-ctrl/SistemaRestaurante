<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Comprobantes;
use App\Services\Pedidos;
use App\Services\Ventas;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El pedido y su venta (auditoría de normalización, bloque 2): la venta
 * guarda su pedido —también la anulada—, el cliente es de la venta, y los
 * estados que no pueden contradecirse los impide la base con un CHECK, también
 * en la vía sin triggers.
 */
class VentaDelPedidoTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(Usuario $usuario): SesionCaja
    {
        $libre = Caja::whereDoesntHave('sesiones', fn ($q) => $q->where('estado', 'ABIERTA'))->orderBy('id')->first()
            ?? Caja::create(['nombre' => 'Caja de la prueba', 'activo' => 1]);

        return (Cajas::sesionDe($usuario) ?? Cajas::abrir($libre, $usuario, 100))->fresh();
    }

    private function efectivo(): array
    {
        return [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]];
    }

    private function vender(?Usuario $usuario = null): Venta
    {
        $usuario ??= $this->usuario('admin');

        return Pedidos::venderEnMostrador($this->turno($usuario), $usuario, [
            ['producto_id' => Producto::where('codigo', 'P-0010')->value('id'), 'cantidad' => 2],
        ], $this->efectivo());
    }

    /** La violación de un CHECK, por SQL: la base la rechaza con su nombre. */
    private function rechaza(string $check, Closure $sql): void
    {
        try {
            $sql();
            $this->fail("La base aceptó algo que {$check} tenía que impedir.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }

    // ================================================== A1 · la venta y su pedido

    public function test_la_clave_vive_en_la_venta_y_el_pedido_no_guarda_venta_ni_cliente(): void
    {
        $this->assertTrue(Schema::hasColumn('ventas', 'pedido_id'));
        $this->assertFalse(Schema::hasColumn('pedidos', 'venta_id'));
        $this->assertFalse(Schema::hasColumn('pedidos', 'cliente_id'));
    }

    /**
     * Al anular, la venta ya no perdía solo su pedido: se perdía el vínculo
     * entero (`pedidos.venta_id` volvía a NULL). Ahora la anulada lo conserva,
     * el pedido tiene todas sus ventas y una sola vigente, y la ficha de la
     * venta anulada dice de qué pedido era y con qué venta se volvió a cobrar.
     */
    public function test_la_venta_anulada_sigue_diciendo_de_que_pedido_era(): void
    {
        $admin = $this->usuario('admin');
        $anulada = $this->vender($admin);
        $pedido = $anulada->pedido;

        Ventas::anular($anulada->fresh(), $admin, 'Se cobró con la forma de pago equivocada');

        $this->assertSame($pedido->id, $anulada->fresh()->pedido->id);
        $this->assertTrue($pedido->fresh()->estaAbierto());
        $this->assertNull($pedido->fresh()->venta);

        $nueva = Pedidos::cobrar($pedido->fresh(), $this->turno($admin), $admin, $this->efectivo());

        $pedido = $pedido->fresh();
        $this->assertSame([$anulada->id, $nueva->id], $pedido->ventas()->orderBy('id')->pluck('id')->all());
        $this->assertSame($nueva->id, $pedido->venta->id);
        $this->assertSame($pedido->id, $nueva->fresh()->pedido_id);

        $this->actingAs($admin)->get(route('ventas.show', $anulada))->assertOk()
            ->assertSee('data-pedido-de-la-anulada="'.$pedido->id.'"', false)
            ->assertSee($pedido->numero_visible)
            ->assertSee(route('ventas.show', $nueva));
    }

    /** Una sola venta vigente por pedido: lo impide la base, no solo el servicio. */
    public function test_un_pedido_no_tiene_dos_ventas_vigentes_ni_por_sql(): void
    {
        $venta = $this->vender();

        $this->rechaza('uq_venta_pedido_cobrado', fn () => DB::table('ventas')->insert([
            'usuario_id' => $venta->usuario_id,
            'sesion_caja_id' => $venta->sesion_caja_id,
            'pedido_id' => $venta->pedido_id,
            'fecha' => now(),
        ]));
    }

    // ============================================== A2 · el cliente es de la venta

    /**
     * El caso que dejaba mal parado al comprobante: se cobra sin cliente, el
     * cliente vuelve y pide factura (sustitución a nombre de su empresa), se
     * anula y se vuelve a cobrar. El comprobante nuevo tiene que salir otra vez
     * a nombre de la empresa, no como recibo al «Cliente varios» del pedido.
     */
    public function test_volver_a_cobrar_una_venta_facturada_por_sustitucion_sale_a_nombre_de_la_empresa(): void
    {
        $admin = $this->usuario('admin');
        $empresa = Cliente::where('tipo_persona', 'JURIDICA')->where('activo', 1)->orderBy('id')->firstOrFail();

        $venta = $this->vender($admin);
        $pedido = $venta->pedido;
        $this->assertNull($venta->cliente_id);
        $this->assertSame('REC', $venta->comprobante->serie->tipo->codigo);

        $factura = Comprobantes::sustituir($venta->comprobante, $admin, $empresa, 'El cliente pide factura a nombre de su empresa');
        $this->assertSame('FAC', $factura->serie->tipo->codigo);

        Ventas::anular($venta->fresh(), $admin, 'Se cobró con la forma de pago equivocada');

        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('data-cliente-del-cobro', false)
            ->assertSee($empresa->nombre);

        $total = Pedidos::totalesDe($pedido->fresh())['total'];
        $this->actingAs($admin)->post(route('pedidos.cobrar.store', $pedido), [
            'total_esperado' => number_format($total, 2, '.', ''),
            'pagos' => $this->efectivo(),
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');

        $nueva = $pedido->fresh()->venta;
        $this->assertSame($empresa->id, (int) $nueva->cliente_id);
        $this->assertSame('FAC', $nueva->comprobante->serie->tipo->codigo);
        $this->assertSame($empresa->nombre, $nueva->comprobante->cliente_nombre);
    }

    // ================================================== A3 · un CHECK por regla

    public function test_ck_ventas_anulacion(): void
    {
        $venta = $this->vender();

        $this->rechaza('ck_ventas_anulacion', fn () => DB::table('ventas')->where('id', $venta->id)->update(['estado' => 'ANULADA']));
        $this->rechaza('ck_ventas_anulacion', fn () => DB::table('ventas')->where('id', $venta->id)->update(['motivo_anulacion' => 'a medias']));
    }

    public function test_ck_ventas_precio_final(): void
    {
        $venta = $this->vender();
        $this->assertFalse((bool) $venta->impuesto_incluido);

        $this->rechaza('ck_ventas_precio_final', fn () => DB::table('ventas')->where('id', $venta->id)->update(['descuento_precio_final' => 1]));
    }

    public function test_ck_sesion_cierre(): void
    {
        $turno = $this->turno($this->usuario('admin'));

        $this->rechaza('ck_sesion_cierre', fn () => DB::table('sesiones_caja')->where('id', $turno->id)->update(['fecha_cierre' => now()]));
    }

    public function test_ck_sesion_cerrada(): void
    {
        $turno = $this->turno($this->usuario('admin'));

        $this->rechaza('ck_sesion_cerrada', fn () => DB::table('sesiones_caja')->where('id', $turno->id)
            ->update(['estado' => 'CERRADA', 'fecha_cierre' => now()]));
    }

    public function test_ck_sesion_fondo(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turno($admin);
        Cajas::cerrar($turno, $admin, 100, null, 50, $turno->huella(), conCuentasAbiertas: true);

        $this->rechaza('ck_sesion_fondo', fn () => DB::table('sesiones_caja')->where('id', $turno->id)->update(['fondo_dejado' => 100.01]));
        $this->rechaza('ck_sesion_fondo', fn () => DB::table('sesiones_caja')->where('id', $turno->id)->update(['fondo_dejado' => -1]));
    }

    public function test_ck_comprobante_anulado(): void
    {
        $comprobante = $this->vender()->comprobante;

        $this->rechaza('ck_comprobante_anulado', fn () => DB::table('comprobantes')->where('id', $comprobante->id)->update(['estado' => 'ANULADO']));
    }

    public function test_ck_comprobante_sustituido(): void
    {
        $comprobante = $this->vender()->comprobante;

        $this->rechaza('ck_comprobante_sustituido', fn () => DB::table('comprobantes')->where('id', $comprobante->id)->update(['sustituido_en' => now()]));
    }

    public function test_ck_cobros_qr_venta(): void
    {
        $admin = $this->usuario('admin');
        $venta = $this->vender($admin);
        $cobro = CobrosQr::generar($this->turno($admin), $admin, 10);
        $this->assertSame(CobroQr::PENDIENTE, $cobro->estado);

        $this->rechaza('ck_cobros_qr_venta', fn () => DB::table('cobros_qr')->where('id', $cobro->id)->update(['venta_id' => $venta->id]));
    }

    public function test_ck_cobros_qr_manual(): void
    {
        $admin = $this->usuario('admin');
        $cobro = CobrosQr::generar($this->turno($admin), $admin, 10);

        $this->rechaza('ck_cobros_qr_manual', fn () => DB::table('cobros_qr')->where('id', $cobro->id)
            ->update(['estado' => 'PAGADO', 'pagado_en' => now(), 'confirmado_por' => 'MANUAL']));
    }

    public function test_ck_pedidodet_entera(): void
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $plato = Producto::where('codigo', 'P-0010')->firstOrFail();

        $this->rechaza('ck_pedidodet_entera', fn () => DB::table('pedido_detalle')->insert([
            'pedido_id' => $pedido->id, 'producto_id' => $plato->id, 'descripcion' => $plato->nombre,
            'cantidad' => 1.5, 'precio_unitario' => $plato->precio_venta, 'usuario_id' => $cajero->id,
        ]));
    }
}
