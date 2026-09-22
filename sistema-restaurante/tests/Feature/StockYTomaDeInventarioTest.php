<?php

namespace Tests\Feature;

use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\Usuario;
use App\Services\Inventario;
use App\Services\TomasInventario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las pantallas del stock (existencias, kardex, ajuste) y de la toma de
 * inventario, recorridas por HTTP como las usa el administrador.
 */
class StockYTomaDeInventarioTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre = 'admin'): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** El refresco llevando stock: caja de 12, costo 4, mínimo 6, con 20 unidades. */
    private function bebida(float $stock = 20): Producto
    {
        // Ninguna otra toma a medias de la base de pruebas estorba al abrir una.
        TomaInventario::where('estado', 'ABIERTA')->get()
            ->each(fn ($t) => TomasInventario::cancelar($t, $this->usuario()));

        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'costo' => 4, 'stock_minimo' => 6, 'stock_actual' => 0,
        ])->save();

        if ($stock > 0) {
            Inventario::inicial($p->fresh(), $stock, $this->usuario());
        }

        return $p->fresh();
    }

    public function test_el_administrador_ve_las_pantallas_y_los_demas_no(): void
    {
        $p = $this->bebida();
        $admin = $this->usuario();
        $toma = TomasInventario::abrir($admin);

        $this->actingAs($admin)->get(route('inventario.index'))->assertOk()
            ->assertSee($p->nombre)->assertSee('1 caja y 8 sueltas')->assertSee('Valor del inventario');
        $this->actingAs($admin)->get(route('inventario.kardex', $p))->assertOk()
            ->assertSee('Stock inicial')->assertSee('20 u.');
        $this->actingAs($admin)->get(route('tomas.index'))->assertOk()
            ->assertSee('Seguir con la toma #'.$toma->id);
        $this->actingAs($admin)->get(route('tomas.show', $toma))->assertOk()
            ->assertSee('Cerrar y ajustar')->assertSee('name="contados['.$p->id.']"', false)
            ->assertSee('name="_envio"', false);

        foreach (['cajero1', 'cocina1'] as $nombre) {
            $otro = $this->usuario($nombre);
            $this->actingAs($otro)->get(route('inventario.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('inventario.kardex', $p))->assertForbidden();
            $this->actingAs($otro)->get(route('tomas.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('tomas.show', $toma))->assertForbidden();
        }
    }

    public function test_sin_productos_con_stock_explica_como_activarlo(): void
    {
        Producto::where('controla_stock', 1)->update(['controla_stock' => 0]);

        $this->actingAs($this->usuario())->get(route('inventario.index'))->assertOk()
            ->assertSee('Todavía nada lleva inventario')->assertSee('«Lleva inventario»', false);
    }

    public function test_el_kardex_de_lo_que_nunca_llevo_stock_no_existe(): void
    {
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        $plato->forceFill(['controla_stock' => false])->save();

        $this->actingAs($this->usuario())->get(route('inventario.kardex', $plato))->assertNotFound();
    }

    public function test_el_ajuste_deja_el_stock_en_lo_contado_y_lo_anota_en_el_kardex(): void
    {
        $p = $this->bebida();

        $this->actingAs($this->usuario())->from(route('inventario.index'))
            ->post(route('inventario.ajuste', $p), ['contado' => 17, 'motivo' => 'Se rompieron 3 botellas', '_envio' => 'ajuste-stock-1'])
            ->assertRedirect(route('inventario.index'))->assertSessionHas('exito');

        $this->assertEquals(17, (float) $p->fresh()->stock_actual);

        $mov = MovimientoInventario::where('producto_id', $p->id)->where('origen', 'AJUSTE')->latest('id')->firstOrFail();
        $this->assertSame('SALIDA', $mov->tipo);
        $this->assertEquals(3, (float) $mov->cantidad);
        $this->assertEquals(20, (float) $mov->stock_anterior);
        $this->assertSame('Se rompieron 3 botellas', $mov->motivo);

        $this->actingAs($this->usuario())->get(route('inventario.kardex', $p))->assertOk()
            ->assertSee('Ajuste')->assertSee('Se rompieron 3 botellas');
    }

    public function test_el_ajuste_pide_motivo_y_no_acepta_negativos(): void
    {
        $p = $this->bebida();

        $this->actingAs($this->usuario())->from(route('inventario.index'))
            ->post(route('inventario.ajuste', $p), ['contado' => -1, 'motivo' => '', '_envio' => 'ajuste-stock-2'])
            ->assertSessionHasErrors(['contado', 'motivo']);

        $this->assertEquals(20, (float) $p->fresh()->stock_actual);
    }

    public function test_el_stock_negativo_se_marca_para_revisar(): void
    {
        $p = $this->bebida(0);
        Inventario::mover($p->id, 2, 'SALIDA', 'AJUSTE', $this->usuario()->id, ['motivo' => 'Vendido sin stock']);

        $this->actingAs($this->usuario())->get(route('inventario.index', ['alerta' => 1]))->assertOk()
            ->assertSee($p->nombre)->assertSee('Revisar: se vendió sin stock');
    }

    public function test_la_toma_completa_ajusta_el_stock_por_la_diferencia(): void
    {
        $p = $this->bebida();
        $admin = $this->usuario();

        $this->actingAs($admin)->post(route('tomas.store'), ['observacion' => 'Fin de mes'])
            ->assertRedirect()->assertSessionHas('exito');

        $toma = TomasInventario::abierta();
        $this->assertNotNull($toma);

        $this->actingAs($admin)->post(route('tomas.contar', $toma), ['contados' => [$p->id => 15]])
            ->assertRedirect(route('tomas.show', $toma))->assertSessionHas('exito');

        $this->actingAs($admin)->get(route('tomas.show', $toma))->assertOk()
            ->assertSee('contado: <b>15</b>', false)->assertSee('−5');

        $this->actingAs($admin)->post(route('tomas.cerrar', $toma), ['_envio' => 'cierre-toma-1'])
            ->assertRedirect(route('tomas.show', $toma))
            ->assertSessionHas('exito', 'Toma cerrada. Se ajustó el stock de 1 producto.');

        $this->assertEquals(15, (float) $p->fresh()->stock_actual);
        $this->assertSame('CERRADA', $toma->fresh()->estado);
        $this->assertTrue(MovimientoInventario::where('toma_id', $toma->id)->where('producto_id', $p->id)
            ->where('tipo', 'SALIDA')->where('cantidad', 5)->exists());

        // El resultado, de solo lectura, con el faltante valorizado: 5 × Bs 4.
        $this->actingAs($admin)->get(route('tomas.show', $toma))->assertOk()
            ->assertDontSee('Guardar conteo')->assertSee('20.00');
        $this->actingAs($admin)->get(route('tomas.index'))->assertOk()->assertSee('Nueva toma de inventario');
    }

    public function test_solo_puede_haber_una_toma_abierta(): void
    {
        $this->bebida();
        $admin = $this->usuario();

        $this->actingAs($admin)->post(route('tomas.store'))->assertSessionHas('exito');
        $this->actingAs($admin)->from(route('tomas.index'))->post(route('tomas.store'))
            ->assertRedirect(route('tomas.index'))->assertSessionHas('error');

        $this->assertSame(1, TomaInventario::where('estado', 'ABIERTA')->count());
    }

    public function test_cancelar_la_toma_no_toca_el_stock(): void
    {
        $p = $this->bebida();
        $admin = $this->usuario();
        $toma = TomasInventario::abrir($admin);
        TomasInventario::contar($toma, $p->id, 3, $admin);

        $this->actingAs($admin)->post(route('tomas.cancelar', $toma))
            ->assertRedirect(route('tomas.index'))->assertSessionHas('exito');

        $this->assertEquals(20, (float) $p->fresh()->stock_actual);
        $this->actingAs($admin)->get(route('tomas.show', $toma))->assertOk()->assertSee('el stock no se tocó');
    }
}
