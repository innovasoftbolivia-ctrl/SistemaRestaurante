<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Usuario;
use App\Support\AlertasStock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La campana de productos por reponer, en la cabecera de todas las pantallas.
 *
 * La semilla no trae ninguno bajo su mínimo, así que cada prueba sube el
 * mínimo de algunos productos en vez de tocar su stock: el stock solo cambia
 * por el kardex.
 */
class AlertasStockTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $usuario): Usuario
    {
        return Usuario::where('usuario', $usuario)->firstOrFail();
    }

    /** Pone en alerta a los primeros `$cuantos` productos activos. */
    private function enAlerta(int $cuantos): void
    {
        Producto::activos()->orderBy('id')->limit($cuantos)->get()
            ->each(fn (Producto $p) => $p->forceFill(['stock_minimo' => (float) $p->stock_actual + 1])->save());
    }

    public function test_la_campana_cuenta_todos_los_productos_por_reponer(): void
    {
        $this->enAlerta(AlertasStock::MOSTRAR + 2);

        $alertas = AlertasStock::para($this->usuario('admin'));

        $this->assertSame(AlertasStock::MOSTRAR + 2, $alertas['total']);
        $this->assertCount(AlertasStock::MOSTRAR, $alertas['productos']);

        $this->actingAs($this->usuario('admin'))->get(route('inicio'))
            ->assertOk()
            ->assertSee('data-alertas-stock="'.(AlertasStock::MOSTRAR + 2).'"', false)
            ->assertSee('Ver los '.(AlertasStock::MOSTRAR + 2));
    }

    public function test_los_agotados_van_primero_en_la_lista(): void
    {
        $this->enAlerta(3);
        $agotado = Producto::activos()->orderByDesc('id')->firstOrFail();
        $agotado->forceFill(['stock_actual' => 0])->save();

        $alertas = AlertasStock::para($this->usuario('admin'));

        $this->assertSame(1, $alertas['agotados']);
        $this->assertSame($agotado->id, $alertas['productos']->first()->id);
    }

    public function test_sin_nada_por_reponer_la_campana_lo_dice(): void
    {
        $this->assertSame(0, Producto::alertasDeStock()->count(), 'la semilla no trae alertas');

        $this->actingAs($this->usuario('almacen'))->get(route('inventario.index'))
            ->assertOk()
            ->assertSee('data-alertas-stock="0"', false)
            ->assertSee('Todo está sobre su stock mínimo.');
    }

    /** El cajero no puede reponer ni comprar: la campana sería ruido. */
    public function test_el_cajero_no_ve_la_campana(): void
    {
        $this->enAlerta(2);

        $this->assertNull(AlertasStock::para($this->usuario('cajero1')));
        $this->actingAs($this->usuario('cajero1'))->get(route('inicio'))
            ->assertOk()
            ->assertDontSee('data-alertas-stock', false);
    }

    /** El panel decía «6» aunque hubiera cuarenta: contaba la lista recortada. */
    public function test_el_panel_de_inicio_muestra_el_total_y_no_solo_los_listados(): void
    {
        $this->enAlerta(9);

        $this->actingAs($this->usuario('admin'))->get(route('inicio'))
            ->assertOk()
            ->assertViewHas('alertasTotal', 9);
    }
}
