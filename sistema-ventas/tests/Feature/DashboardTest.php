<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function cocina(): Usuario
    {
        return Usuario::where('usuario', 'cocina1')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), 200);
    }

    private function vender(SesionCaja $sesion, float $cantidad = 2): Venta
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [[
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) $producto->precio_venta,
            ]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- acceso

    /** La portada es para todos: cada bloque se muestra según el rol. */
    public function test_todos_los_roles_entran_a_la_portada(): void
    {
        foreach ([$this->admin(), $this->cajero(), $this->cocina()] as $usuario) {
            $this->actingAs($usuario)->get('/inicio')->assertOk();
        }
    }

    public function test_un_visitante_no_entra_a_la_portada(): void
    {
        $this->get('/inicio')->assertRedirect('/login');
    }

    public function test_el_menu_ofrece_la_portada_a_todos(): void
    {
        foreach ([$this->admin(), $this->cajero()] as $usuario) {
            $this->actingAs($usuario)
                ->get('/perfil')
                ->assertSee('href="'.url('/inicio').'"', false);
        }
    }

    // ------------------------------------------------------ bloques por rol

    /** El resumen del negocio es información de gestión: pide `reportes.ver`. */
    public function test_el_cajero_no_ve_el_resumen_del_negocio(): void
    {
        $respuesta = $this->actingAs($this->cajero())->get('/inicio')->assertOk();

        $this->assertNull($respuesta->viewData('hoy'));
        $this->assertNull($respuesta->viewData('serie'));
        $this->assertNull($respuesta->viewData('ultimas'));
        $this->assertFalse($respuesta->viewData('gestion'));
    }

    public function test_el_administrador_ve_el_resumen_completo(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get('/inicio')->assertOk();

        $this->assertNotNull($respuesta->viewData('hoy'));
        $this->assertNotNull($respuesta->viewData('serie'));
        $this->assertNotNull($respuesta->viewData('ultimas'));
        $this->assertTrue($respuesta->viewData('gestion'));
    }

    /** La cocina no vende, así que no tiene ventas propias que mostrar. */
    public function test_la_cocina_no_ve_ventas_propias_ni_el_resumen(): void
    {
        $respuesta = $this->actingAs($this->cocina())->get('/inicio')->assertOk();

        $this->assertNull($respuesta->viewData('mias'));
        $this->assertNull($respuesta->viewData('hoy'));
    }

    // -------------------------------------------------------------- cifras

    public function test_muestra_el_turno_abierto_del_usuario(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get('/inicio')->assertOk();

        $this->assertSame($sesion->id, $respuesta->viewData('sesion')->id);
        $this->assertSame(1, $respuesta->viewData('sesion')->ventas_count);
    }

    public function test_sin_turno_abierto_lo_dice(): void
    {
        $this->actingAs($this->admin())
            ->get('/inicio')
            ->assertOk()
            ->assertSee('Sin caja abierta');

        $this->assertNull(
            $this->actingAs($this->admin())->get('/inicio')->viewData('sesion')
        );
    }

    /** «Lo que llevo vendido hoy» es lo propio, no lo del negocio. */
    public function test_las_ventas_propias_solo_cuentan_las_de_uno(): void
    {
        $turnoCajero = $this->turno($this->cajero());
        $venta = $this->vender($turnoCajero, 2);

        // Lo que vendió el cajero cuenta para el cajero...
        $mias = $this->actingAs($this->cajero())->get('/inicio')->viewData('mias');

        $this->assertSame(1, $mias['operaciones']);
        $this->assertSame((float) $venta->fresh()->total, $mias['monto']);

        // ...y no para el administrador, que no vendió nada.
        $delAdmin = $this->actingAs($this->admin())->get('/inicio')->viewData('mias');

        $this->assertSame(0, $delAdmin['operaciones']);
        $this->assertSame(0.0, $delAdmin['monto']);
    }

    public function test_una_venta_anulada_no_cuenta_en_el_dia(): void
    {
        $sesion = $this->turno();
        $vigente = $this->vender($sesion, 2);
        $anulada = $this->vender($sesion, 3);

        Ventas::anular($anulada, $this->admin(), 'Error de cobro');

        $hoy = $this->actingAs($this->admin())->get('/inicio')->viewData('hoy');

        $this->assertSame(1, $hoy['hoy']['operaciones']);
        $this->assertSame((float) $vigente->fresh()->total, $hoy['hoy']['monto']);
    }

    /** Sin ventas ayer no hay porcentaje que calcular: dividir por cero. */
    public function test_sin_ventas_ayer_no_hay_variacion(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $hoy = $this->actingAs($this->admin())->get('/inicio')->viewData('hoy');

        $this->assertSame(0.0, $hoy['ayer']['monto']);
        $this->assertNull($hoy['variacion']);

        $this->actingAs($this->admin())->get('/inicio')->assertSee('ayer no hubo ventas');
    }

    public function test_la_serie_cubre_dos_semanas_con_los_huecos_en_cero(): void
    {
        $serie = $this->actingAs($this->admin())->get('/inicio')->viewData('serie');

        $this->assertCount(14, $serie);
        // Por jornada: a la 01:30 la última barra todavía es la de ayer.
        $jornada = Carbon::parse(Config::jornadaActual());
        $this->assertSame($jornada->format('d/m'), end($serie)['etiqueta']);
        $this->assertSame($jornada->copy()->subDays(13)->format('d/m'), $serie[0]['etiqueta']);
    }

    public function test_la_portada_publica_los_datos_del_grafico(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $this->actingAs($this->admin())
            ->get('/inicio')
            ->assertOk()
            ->assertSee('data-apexchart', false)
            ->assertSee('Últimas dos semanas', false);
    }
}
