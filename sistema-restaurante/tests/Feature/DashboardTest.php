<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
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

        foreach (['hoy', 'porHora', 'pagos', 'pedidos', 'cocina', 'top', 'stock', 'cajasAbiertas'] as $bloque) {
            $this->assertNull($respuesta->viewData($bloque), "el cajero no debería ver «{$bloque}»");
        }
        $this->assertFalse($respuesta->viewData('gestion'));
        $respuesta->assertDontSee('data-se-actualiza', false);
    }

    public function test_el_administrador_ve_el_resumen_completo(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get('/inicio')->assertOk();

        foreach (['hoy', 'porHora', 'pagos', 'pedidos', 'cocina', 'top', 'stock', 'cajasAbiertas'] as $bloque) {
            $this->assertNotNull($respuesta->viewData($bloque), "falta «{$bloque}» en el panel");
        }
        $this->assertTrue($respuesta->viewData('gestion'));
        $respuesta->assertSee('data-se-actualiza', false)
            ->assertSee('data-formas-de-pago', false)
            ->assertSee('data-ultimos-pedidos', false)
            ->assertSee('data-cocina-ahora', false)
            ->assertSee('data-top-hoy', false)
            ->assertSee('data-por-comprar', false);
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

    /** Sin ventas el mismo día de la semana pasada no hay porcentaje: dividir por cero. */
    public function test_sin_ventas_la_semana_pasada_no_hay_variacion(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $hoy = $this->actingAs($this->admin())->get('/inicio')->viewData('hoy');

        $this->assertSame(0.0, $hoy['antes']['monto']);
        $this->assertNull($hoy['variacion']);

        $this->actingAs($this->admin())->get('/inicio')->assertSee("el {$hoy['dia']} pasado no hubo ventas");
    }

    /**
     * Se compara con el mismo día de la semana pasada, no con ayer: un lunes
     * contra el domingo engaña.
     */
    public function test_compara_con_el_mismo_dia_de_la_semana_pasada(): void
    {
        $sesion = $this->turno();
        $antes = $this->vender($sesion, 2);
        $ayer = $this->vender($sesion, 5);
        $hoy = $this->vender($sesion, 4);

        $jornada = Carbon::parse(Config::jornadaActual())->setTime(13, 0);
        $antes->forceFill(['fecha' => $jornada->copy()->subWeek()])->saveQuietly();
        $ayer->forceFill(['fecha' => $jornada->copy()->subDay()])->saveQuietly();

        $datos = $this->actingAs($this->admin())->get('/inicio')->viewData('hoy');

        $this->assertSame((float) $hoy->fresh()->total, $datos['hoy']['monto']);
        $this->assertSame((float) $antes->fresh()->total, $datos['antes']['monto']);
        $this->assertSame(100.0, $datos['variacion'], '4 platos contra 2: el doble');
        $this->assertSame($jornada->locale('es')->isoFormat('dddd'), $datos['dia']);
    }

    public function test_formas_de_pago_y_lo_mas_vendido_de_hoy(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 3);

        $respuesta = $this->actingAs($this->admin())->get('/inicio');

        $pagos = $respuesta->viewData('pagos');
        $this->assertCount(1, $pagos);
        $this->assertSame((float) $venta->fresh()->total, (float) $pagos->first()->monto);

        $top = $respuesta->viewData('top');
        $this->assertSame('P-0004', Producto::find($top->first()->id)->codigo);
        $this->assertSame(3.0, (float) $top->first()->unidades);

        // En bolivianos.
        $respuesta->assertSee('Bs '.number_format((float) $venta->fresh()->total, 2), false);
    }

    /** Los últimos pedidos dicen en qué están, y la cocina cuántos esperan. */
    public function test_ultimos_pedidos_y_cocina_ahora(): void
    {
        $sesion = $this->turno();
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        Pedidos::venderEnMostrador($sesion->fresh(), $this->admin(), [
            ['producto_id' => $plato->id, 'cantidad' => 1],
        ], [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]]);

        $respuesta = $this->actingAs($this->admin())->get('/inicio');

        $pedido = $respuesta->viewData('pedidos')->first();
        $this->assertSame('Por hacer', $pedido['estado']);
        $this->assertSame(1, $respuesta->viewData('cocina')['hacer']);
        $this->assertSame(0, $respuesta->viewData('cocina')['espera']);
        $respuesta->assertSee('#'.$pedido['pedido']->numero_dia);
    }

    public function test_ventas_por_hora_frente_a_un_dia_normal(): void
    {
        $this->assertNull($this->actingAs($this->admin())->get('/inicio')->viewData('porHora'));

        $sesion = $this->turno();
        $venta = $this->vender($sesion, 2);
        $hora = (int) $venta->fresh()->fecha->format('G');

        $porHora = $this->actingAs($this->admin())->get('/inicio')->viewData('porHora');

        $i = array_search($hora.'h', $porHora['horas'], true);
        $this->assertNotFalse($i);
        $this->assertSame((float) $venta->fresh()->total, $porHora['hoy'][$i]);
        $this->assertSame(0.0, $porHora['promedio'][$i]);

        $this->actingAs($this->admin())->get('/inicio')
            ->assertSee('data-apexchart', false)
            ->assertSee('Ventas por hora');
    }
}
