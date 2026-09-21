<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El pedido en el comprobante impreso.
 *
 * El ticket es con lo que el cliente reclama su plato: se sienta donde quiera
 * o espera para llevar, quien lleva los platos canta el número, y el cliente
 * lo compara con su papel. Por eso el ticket encabeza con el número del
 * pedido —lo más grande del papel— y con «comer aquí» o «para llevar».
 *
 * Lo que se cuida acá es que la línea aparezca con lo que corresponde en cada
 * caso, y que NO aparezca cuando no hay pedido: una venta vieja, de antes de
 * los pedidos, no tiene número que cantar.
 */
class TicketDelPedidoTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function plato(string $codigo = 'P-0004'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    private function turno(Usuario $usuario): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100))->fresh();
    }

    /** Vende en el mostrador un plato, para comer aquí o para llevar. Devuelve la venta. */
    private function vender(string $tipo, ?string $nombre = null): Venta
    {
        $cajero = $this->usuario('cajero1');

        return Pedidos::venderEnMostrador(
            sesion: $this->turno($cajero),
            usuario: $cajero,
            lineas: [['producto_id' => $this->plato()->id, 'cantidad' => 2]],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]],
            tipo: $tipo,
            nombreCliente: $nombre,
        )->fresh();
    }

    /** Lo que se lee en el papel: el HTML sin etiquetas y con los espacios juntos. */
    private function texto(string $html): string
    {
        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    /** El HTML del ticket de 80 mm de la venta. */
    private function ticket(Venta $venta): string
    {
        $comprobante = $venta->comprobante;
        $this->assertNotNull($comprobante, 'la venta salió sin comprobante: no hay ticket que mirar');

        return $this->actingAs($this->usuario('admin'))
            ->get(route('comprobantes.imprimir', $comprobante))
            ->assertOk()
            ->getContent();
    }

    public function test_el_ticket_para_comer_aqui_dice_el_numero_y_comer_aqui(): void
    {
        $venta = $this->vender(Pedido::LOCAL);
        $pedido = $venta->pedido;

        $this->assertNotNull($pedido, 'la venta del mostrador no encontró su pedido: falta Venta::pedido()');

        $html = $this->ticket($venta);

        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} COMER AQUÍ", $this->texto($html),
            'el ticket no encabeza con el número de pedido y «comer aquí»');
        $this->assertStringContainsString('data-numero-pedido="'.$pedido->numero_dia.'"', $html);
        $this->assertStringNotContainsString('PARA LLEVAR', $html);
    }

    public function test_el_ticket_de_un_pedido_para_llevar_lo_dice_con_el_nombre(): void
    {
        $venta = $this->vender(Pedido::LLEVAR, 'Rosaura');
        $pedido = $venta->pedido;

        $html = $this->ticket($venta);

        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} PARA LLEVAR", $this->texto($html),
            'el ticket para llevar no lo dice');
        $this->assertStringContainsString('Rosaura', $html);
        $this->assertStringNotContainsString('COMER AQUÍ', $html);
    }

    /**
     * El número del pedido es lo más grande del ticket: más que el total y que
     * el número de documento. Es lo que el cliente compara cuando cantan su
     * pedido.
     */
    public function test_el_numero_del_pedido_es_lo_mas_grande_del_ticket(): void
    {
        $html = $this->ticket($this->vender(Pedido::LOCAL));

        preg_match_all('/font-size:\s*(\d+)px/', $html, $tamanos);
        preg_match('/\.pedido-numero\s*\{[^}]*font-size:\s*(\d+)px/', $html, $numero);

        $this->assertNotEmpty($numero, 'el ticket no tiene estilo para el número del pedido');
        $this->assertSame(max(array_map('intval', $tamanos[1])), (int) $numero[1],
            'hay algo en el ticket más grande que el número del pedido');
    }

    /**
     * Una venta sin pedido —las de antes de que el mostrador tomara pedidos,
     * o una registrada por fuera— no imprime la línea ni deja un hueco donde
     * iría.
     */
    public function test_una_venta_sin_pedido_no_imprime_la_linea(): void
    {
        $admin = $this->usuario('admin');

        $venta = Ventas::registrar(
            sesion: $this->turno($admin),
            usuario: $admin,
            lineas: [['producto_id' => $this->plato()->id, 'cantidad' => 2]],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]],
        )->fresh();

        $this->assertNull($venta->pedido, 'una venta registrada por fuera del mostrador no debería tener pedido');

        $html = $this->ticket($venta);

        $this->assertStringNotContainsString('PEDIDO #', $html);
        $this->assertStringNotContainsString('PARA LLEVAR', $html);
        $this->assertStringNotContainsString('class="pedido"', $html);
    }
}
