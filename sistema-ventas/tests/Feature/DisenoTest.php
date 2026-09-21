<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El marco de todas las pantallas y los ajustes de diseño que llevan una
 * regla detrás: el nombre del negocio, el estado de la caja en la cabecera,
 * abrir la caja desde el mostrador y los platos sin foto.
 */
class DisenoTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** Un cajero sin turno abierto: cierra en la base el que pudiera tener. */
    private function cajeroSinCaja(): Usuario
    {
        $cajero = $this->usuario('cajero1');
        SesionCaja::abiertas()->where('usuario_apertura_id', $cajero->id)->update([
            'estado' => 'CERRADA', 'fecha_cierre' => now(), 'usuario_cierre_id' => $cajero->id,
            'monto_esperado' => 0, 'monto_declarado' => 0,
        ]);

        return $cajero;
    }

    public function test_el_menu_lateral_lleva_el_nombre_del_negocio_y_no_el_generico(): void
    {
        $this->actingAs($this->usuario('admin'))->get(route('inicio'))->assertOk()
            ->assertSee('data-marca-negocio', false)
            ->assertSeeText(Config::negocio())
            ->assertDontSee('images/logo/logo.svg', false);
    }

    /** El buscador de empleados de la cabecera ya no está: casi no se usaba. */
    public function test_la_cabecera_no_trae_el_buscador_de_empleados(): void
    {
        $this->actingAs($this->usuario('admin'))->get(route('inicio'))->assertOk()
            ->assertDontSee('buscar-empleado', false);
    }

    /** El título no se repite a la derecha; la ruta sale solo con un nivel de más. */
    public function test_la_ruta_solo_sale_cuando_hay_de_donde_venir(): void
    {
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->get(route('inicio'))->assertOk()
            ->assertDontSee('aria-label="Ruta de navegación"', false);
        $this->actingAs($admin)->get(route('productos.create'))->assertOk()
            ->assertSee('aria-label="Ruta de navegación"', false);
    }

    public function test_la_cabecera_dice_si_tu_caja_esta_abierta(): void
    {
        $cajero = $this->cajeroSinCaja();

        $this->actingAs($cajero)->get(route('ventas.index'))->assertOk()
            ->assertSee('data-estado-caja="cerrada"', false)
            ->assertSeeText('Sin caja abierta');

        $caja = Caja::create(['nombre' => 'Caja diseño', 'activo' => 1]);
        Cajas::abrir($caja, $cajero, 50);

        $this->actingAs($cajero)->get(route('ventas.index'))->assertOk()
            ->assertSee('data-estado-caja="abierta"', false)
            ->assertSeeText('Caja diseño abierta');

        // La cocina no maneja caja: no ve el estado.
        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertDontSee('data-estado-caja', false);
    }

    /** Sin caja abierta, el mostrador la abre ahí mismo y vuelve a vender. */
    public function test_la_caja_se_abre_desde_el_punto_de_venta(): void
    {
        $cajero = $this->cajeroSinCaja();
        $caja = Caja::create(['nombre' => 'Caja mostrador', 'activo' => 1]);

        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
            ->assertSee('data-abrir-caja-desde-pos', false)
            ->assertSee('name="volver" value="pos"', false)
            ->assertSee(route('caja.abrir'), false);

        $this->actingAs($cajero)->post(route('caja.abrir'), [
            'caja_id' => $caja->id, 'monto_inicial' => '80.00', 'volver' => 'pos',
        ])->assertRedirect(route('pos.index'));

        $this->assertSame($caja->id, Cajas::sesionDe($cajero)?->caja_id);
    }

    /** Desde la pantalla de Caja se sigue volviendo a Caja, y `volver` no admite otro destino. */
    public function test_abrir_desde_caja_vuelve_a_caja(): void
    {
        $cajero = $this->cajeroSinCaja();
        $caja = Caja::create(['nombre' => 'Caja vuelta', 'activo' => 1]);

        $this->actingAs($cajero)->post(route('caja.abrir'), [
            'caja_id' => $caja->id, 'monto_inicial' => '10.00', 'volver' => 'https://otro-sitio.example',
        ])->assertRedirect(route('caja.index'));
    }

    /** Un plato sin foto muestra sus iniciales sobre el color de su categoría. */
    public function test_el_plato_sin_foto_muestra_sus_iniciales(): void
    {
        $plato = Producto::activos()->whereNull('imagen')->firstOrFail();
        $plato->update(['nombre' => 'Sopa de maní']);

        // «de» no cuenta: Sopa de maní es SM.
        $this->actingAs($this->usuario('admin'))->get(route('productos.index', ['buscar' => 'Sopa de maní']))->assertOk()
            ->assertSee('data-sin-foto', false)
            ->assertSee('>SM<', false);
    }
}
