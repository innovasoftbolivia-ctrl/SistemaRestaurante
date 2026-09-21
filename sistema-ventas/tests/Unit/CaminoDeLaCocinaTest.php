<?php

namespace Tests\Unit;

use App\Models\PedidoDetalle;
use PHPUnit\Framework\TestCase;

/**
 * El camino de un plato por la cocina, sin base de datos: es una tabla de
 * reglas y se prueba como tal. Cómo se aplica —quién, cuándo, con qué cuenta—
 * lo prueban `CocinaTest` y `PedidosTest`.
 */
class CaminoDeLaCocinaTest extends TestCase
{
    private function plato(string $estado): PedidoDetalle
    {
        return new PedidoDetalle(['estado_cocina' => $estado]);
    }

    public function test_el_boton_de_la_cocina_lleva_al_siguiente_paso_hasta_entregar(): void
    {
        $this->assertSame(PedidoDetalle::EN_PREPARACION, $this->plato(PedidoDetalle::PENDIENTE)->siguiente_estado);
        $this->assertSame(PedidoDetalle::LISTO, $this->plato(PedidoDetalle::EN_PREPARACION)->siguiente_estado);
        $this->assertSame(PedidoDetalle::ENTREGADO, $this->plato(PedidoDetalle::LISTO)->siguiente_estado);
        $this->assertNull($this->plato(PedidoDetalle::ENTREGADO)->siguiente_estado);
        $this->assertNull($this->plato(PedidoDetalle::CANCELADO)->siguiente_estado);
    }

    /** Se cancela lo que todavía no salió de la cocina; lo listo ya se sirve. */
    public function test_solo_se_cancela_lo_que_no_esta_hecho(): void
    {
        $this->assertTrue($this->plato(PedidoDetalle::PENDIENTE)->puedePasarA(PedidoDetalle::CANCELADO));
        $this->assertTrue($this->plato(PedidoDetalle::EN_PREPARACION)->puedePasarA(PedidoDetalle::CANCELADO));
        $this->assertFalse($this->plato(PedidoDetalle::LISTO)->puedePasarA(PedidoDetalle::CANCELADO));
        $this->assertFalse($this->plato(PedidoDetalle::ENTREGADO)->puedePasarA(PedidoDetalle::CANCELADO));
    }

    /** Nada vuelve atrás, y de los estados finales no se sale. */
    public function test_no_se_vuelve_atras_ni_se_sale_del_final(): void
    {
        $orden = [PedidoDetalle::PENDIENTE, PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO];

        foreach ($orden as $i => $desde) {
            foreach (array_slice($orden, 0, $i + 1) as $atras) {
                $this->assertFalse($this->plato($desde)->puedePasarA($atras), "{$desde} no puede volver a {$atras}");
            }
        }

        foreach ([PedidoDetalle::ENTREGADO, PedidoDetalle::CANCELADO] as $final) {
            $this->assertSame([], PedidoDetalle::TRANSICIONES[$final]);
        }
    }
}
