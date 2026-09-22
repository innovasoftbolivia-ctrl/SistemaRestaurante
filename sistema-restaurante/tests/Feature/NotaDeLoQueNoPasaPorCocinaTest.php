<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La nota de una bebida («sin hielo») no pasa por la cocina, y por eso no
 * salía en ningún lado: ni en la pantalla de la cocina ni en la comanda, que
 * son de lo que se cocina. Ahora sale donde la mira quien la entrega: en el
 * pedido de la cocina (junto a los platos que lleva), en el ticket y en el
 * detalle de la venta.
 */
class NotaDeLoQueNoPasaPorCocinaTest extends TestCase
{
    use DatabaseTransactions;

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function vender(array $lineas): Venta
    {
        $turno = Cajas::sesionDe($this->cajero()) ?? Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100);

        return Pedidos::venderEnMostrador(
            sesion: $turno->fresh(),
            usuario: $this->cajero(),
            lineas: $lineas,
            pagos: [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        )->fresh();
    }

    public function test_la_nota_de_la_bebida_sale_en_la_cocina_el_ticket_y_la_venta(): void
    {
        $bebida = Producto::where('codigo', 'P-0005')->firstOrFail();
        $this->assertFalse((bool) $bebida->categoria->pasa_por_cocina, 'la bebida de prueba no pasa por la cocina');

        $venta = $this->vender([
            ['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1, 'nota' => 'bien cocido'],
            ['producto_id' => $bebida->id, 'cantidad' => 2, 'nota' => 'sin hielo'],
        ]);

        // En la cocina: la tarjeta del pedido la muestra en «Además lleva».
        $this->actingAs($this->cajero())->get(route('cocina.index'))
            ->assertOk()
            ->assertSee('data-sin-cocina', false)
            ->assertSee('Además lleva')
            ->assertSee('sin hielo')
            ->assertSee('bien cocido');

        $this->actingAs($this->cajero())->get(route('cocina.pendientes'))
            ->assertOk()
            ->assertJsonFragment(['nota' => 'sin hielo']);

        // En el ticket con el que se entrega en el mostrador.
        $this->actingAs($this->cajero())->get(route('comprobantes.imprimir', $venta->comprobante))
            ->assertOk()
            ->assertSee('data-nota-linea', false)
            ->assertSee('sin hielo');

        // Y en el detalle de la venta.
        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $this->actingAs($admin)->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertSee('sin hielo')
            ->assertSee('bien cocido');
    }

    public function test_sin_nota_no_se_agrega_nada(): void
    {
        $venta = $this->vender([
            ['producto_id' => Producto::where('codigo', 'P-0005')->value('id'), 'cantidad' => 1],
        ]);

        $this->assertSame([], $venta->notasPorProducto());
        $this->actingAs($this->cajero())->get(route('comprobantes.imprimir', $venta->comprobante))
            ->assertOk()
            ->assertDontSee('data-nota-linea', false);
    }
}
