<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Pedidos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El cajero ve la cocina para llevar los platos (`cocina.entregar`): entrega
 * lo que la cocina dejó listo, pero no empieza ni termina nada. La cocina
 * sigue moviendo la preparación con `cocina.ver`.
 */
class EntregaDesdeElMostradorTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** Un pedido de la jornada con un plato de cocina, en el estado pedido. */
    private function linea(string $estado = PedidoDetalle::PENDIENTE): PedidoDetalle
    {
        $cajero = $this->usuario('cajero1');
        $plato = Producto::activos()
            ->whereHas('categoria', fn ($q) => $q->where('pasa_por_cocina', true))
            ->orderBy('id')->firstOrFail();

        $linea = Pedidos::agregarLinea(Pedidos::abrir(Pedido::LOCAL, $cajero), $plato->load('categoria'), 1, null, $cajero);
        $cocina = $this->usuario('cocina1');

        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO] as $paso) {
            if ($linea->fresh()->estado_cocina === $estado) {
                break;
            }
            Pedidos::actualizarEstadoLinea($linea->fresh(), $paso, $cocina);
        }

        return $linea->fresh();
    }

    public function test_el_cajero_ve_la_cocina_y_el_menu_la_ofrece(): void
    {
        $this->linea();

        $this->actingAs($this->usuario('cajero1'))->get(route('cocina.index'))
            ->assertOk()
            ->assertSee('Ves la cocina para entregar')
            ->assertSee('Esperando a la cocina')
            // Ni «Empezar» ni «Listo»: eso es de la cocina.
            ->assertDontSee('data-avanzar-pedido="hacer"', false)
            ->assertDontSee('data-comanda-boton', false);

        $this->actingAs($this->usuario('cajero1'))->get(route('pos.index'))
            ->assertOk()
            ->assertSee('href="'.url('/cocina').'"', false);
    }

    public function test_el_cajero_no_empieza_ni_termina_platos(): void
    {
        $linea = $this->linea();
        $cajero = $this->usuario('cajero1');

        $this->actingAs($cajero)
            ->post(route('cocina.avanzar', $linea->pedido), ['estado' => PedidoDetalle::EN_PREPARACION])
            ->assertForbidden();

        $this->actingAs($cajero)
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::EN_PREPARACION])
            ->assertForbidden();

        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->fresh()->estado_cocina);
    }

    /** Ni «entregar» lo que la cocina todavía no terminó. */
    public function test_el_cajero_no_entrega_lo_que_no_esta_listo(): void
    {
        $linea = $this->linea(PedidoDetalle::EN_PREPARACION);

        $this->actingAs($this->usuario('cajero1'))
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::ENTREGADO])
            ->assertForbidden();

        $this->assertSame(PedidoDetalle::EN_PREPARACION, $linea->fresh()->estado_cocina);
    }

    public function test_el_cajero_entrega_el_pedido_listo(): void
    {
        $linea = $this->linea(PedidoDetalle::LISTO);

        $this->actingAs($this->usuario('cajero1'))->get(route('cocina.index'))
            ->assertSee('data-avanzar-pedido="entregar"', false);

        $this->actingAs($this->usuario('cajero1'))
            ->from(route('cocina.index'))
            ->post(route('cocina.entregar', $linea->pedido), ['_envio' => 'entrega-'.$linea->id])
            ->assertSessionHas('exito');

        $this->assertSame(PedidoDetalle::ENTREGADO, $linea->fresh()->estado_cocina);
    }

    public function test_el_cajero_entrega_un_plato_suelto_listo(): void
    {
        $linea = $this->linea(PedidoDetalle::LISTO);

        $this->actingAs($this->usuario('cajero1'))
            ->from(route('cocina.index'))
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::ENTREGADO])
            ->assertSessionHas('exito');

        $this->assertSame(PedidoDetalle::ENTREGADO, $linea->fresh()->estado_cocina);
    }

    /** La cocina no pierde nada: sigue moviendo toda la preparación. */
    public function test_la_cocina_sigue_pudiendo_todo(): void
    {
        $linea = $this->linea();

        $this->actingAs($this->usuario('cocina1'))
            ->from(route('cocina.index'))
            ->post(route('cocina.avanzar', $linea->pedido), ['estado' => PedidoDetalle::EN_PREPARACION, '_envio' => 'avanza-'.$linea->id])
            ->assertSessionHas('exito');

        $this->assertSame(PedidoDetalle::EN_PREPARACION, $linea->fresh()->estado_cocina);

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))
            ->assertDontSee('Ves la cocina para entregar');
    }
}
