<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\DevolucionCompra;
use App\Models\MetodoPago;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Notificaciones;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La campana: los seis avisos (pedido demorado, listo sin entregar, por
 * cobrar, descuadre de caja, ventas anuladas, proveedor que debe) más el de
 * stock; que cada rol vea lo suyo, y que se vayan solos al resolverse.
 */
class NotificacionesTest extends TestCase
{
    use ConDatosDeInventario;
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        $cajero = $this->usuario('cajero1');

        return Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
    }

    /** Un plato vendido en el mostrador: queda en la cocina, por hacer. */
    private function vender(): Venta
    {
        return Pedidos::venderEnMostrador(
            $this->turno()->fresh(), $this->usuario('cajero1'),
            [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1]],
            [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        )->fresh();
    }

    /** @return array<int, string> los títulos de un grupo, para quien mira */
    private function titulos(string $quien, string $grupo): array
    {
        $avisos = Notificaciones::para($this->usuario($quien));

        return array_column($avisos['grupos'][$grupo]['avisos'] ?? [], 'titulo');
    }

    public function test_pedido_demorado_en_la_cocina(): void
    {
        $venta = $this->vender();
        $numero = $venta->pedido->numero_dia;

        $this->assertSame([], $this->titulos('admin', 'cocina'), 'recién pedido no es un aviso');

        PedidoDetalle::where('pedido_id', $venta->pedido_id)->update(['creado_en' => now()->subMinutes(25)]);

        $this->assertSame(["#{$numero} espera hace 25 min"], $this->titulos('admin', 'cocina'));
        $this->assertSame(["#{$numero} espera hace 25 min"], $this->titulos('cajero1', 'cocina'));
        $this->assertTrue(Notificaciones::para($this->usuario('admin'))['grave']);

        // Sale de la cocina: el aviso se va solo.
        Pedidos::avanzarTodo($venta->pedido->fresh(), PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));
        Pedidos::avanzarTodo($venta->pedido->fresh(), PedidoDetalle::LISTO, $this->usuario('cocina1'));
        $this->assertSame([], $this->titulos('admin', 'cocina'));
    }

    public function test_pedido_listo_sin_entregar(): void
    {
        $venta = $this->vender();
        $numero = $venta->pedido->numero_dia;
        Pedidos::avanzarTodo($venta->pedido->fresh(), PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));
        Pedidos::avanzarTodo($venta->pedido->fresh(), PedidoDetalle::LISTO, $this->usuario('cocina1'));

        $this->assertSame([], $this->titulos('cajero1', 'cocina'), 'recién listo no es un aviso');

        PedidoDetalle::where('pedido_id', $venta->pedido_id)->update(['actualizado_en' => now()->subMinutes(7)]);

        $this->assertSame(["#{$numero} listo hace 7 min"], $this->titulos('cajero1', 'cocina'));

        Pedidos::entregarLoListo($venta->pedido->fresh(), $this->usuario('cajero1'));
        $this->assertSame([], $this->titulos('cajero1', 'cocina'));
    }

    /** El cobro anulado deja el pedido por cobrar, y la anulación cuenta en la caja. */
    public function test_pedido_por_cobrar_y_ventas_anuladas(): void
    {
        $venta = $this->vender();
        $numero = $venta->pedido->numero_dia;

        Ventas::anular($venta, $this->usuario('admin'), 'Se cobró con otro medio');

        $this->assertContains("#{$numero} por cobrar", $this->titulos('cajero1', 'cocina'));

        $caja = $this->titulos('admin', 'caja');
        $this->assertCount(1, $caja);
        $this->assertStringStartsWith('1 venta de hoy anulada', $caja[0]);
        $this->assertSame([], $this->titulos('cajero1', 'caja'), 'las anulaciones son del administrador');

        // Se vuelve a cobrar: el pedido deja de estar por cobrar.
        Pedidos::cobrar($venta->pedido->fresh(), $this->turno()->fresh(), $this->usuario('cajero1'), [
            ['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null],
        ]);
        $this->assertNotContains("#{$numero} por cobrar", $this->titulos('cajero1', 'cocina'));
    }

    public function test_cierre_de_caja_con_faltante(): void
    {
        $admin = $this->usuario('admin');
        $turno = Cajas::abrir(Caja::create(['nombre' => 'Caja avisos', 'activo' => 1]), $admin, 100);
        Cajas::cerrar($turno->fresh(), $admin, 90, 'Faltó cambio', fondo: 0, conCuentasAbiertas: true);

        $this->assertSame(['Caja avisos cerró con faltante de Bs 10.00'], $this->titulos('admin', 'caja'));
        $this->assertTrue(Notificaciones::para($admin)['grave']);
    }

    public function test_el_proveedor_debe_la_reposicion_hace_mas_de_una_semana(): void
    {
        $datos = $this->datosDeInventario($this->usuario('admin'));

        $this->assertSame([], array_filter($this->titulos('admin', 'inventario'), fn ($t) => str_contains($t, 'debe')),
            'recién devuelto no es un aviso');

        DevolucionCompra::whereKey($datos['devolucion'])->update(['fecha' => now()->subDays(8)]);

        $titulos = $this->titulos('admin', 'inventario');
        $this->assertContains('Proveedor del recorrido debe 2 u.', $titulos);
        $this->assertSame([], $this->titulos('cajero1', 'inventario'));
    }

    public function test_cada_rol_ve_lo_suyo_y_la_cocina_nada(): void
    {
        $this->assertNull(Notificaciones::para($this->usuario('cocina1')), 'la cocina ya mira su pantalla');

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))
            ->assertOk()->assertDontSee('data-notificaciones', false);

        $this->actingAs($this->usuario('cajero1'))->get(route('pos.index'))
            ->assertOk()->assertSee('data-notificaciones="0"', false)
            ->assertSee('Nada pendiente: todo en orden.');
    }

    /** La página la refresca cada minuto: el pedazo trae su marca, y cuenta. */
    public function test_la_campana_se_refresca_sola(): void
    {
        $venta = $this->vender();
        DB::table('pedido_detalle')->where('pedido_id', $venta->pedido_id)->update(['creado_en' => now()->subMinutes(30)]);

        $this->actingAs($this->usuario('admin'))->get(route('notificaciones'))
            ->assertOk()
            ->assertHeader('X-Notificaciones', '1')
            ->assertSee('data-notificaciones="1"', false)
            ->assertSee('espera hace 30 min')
            ->assertSee(route('cocina.index'), false);

        // Sin sesión, no hay campana: al login.
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->get(route('notificaciones'))->assertRedirect();
    }
}
