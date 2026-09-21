<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Usuario;
use Tests\TestCase;

/**
 * El buscador del mostrador y de la carta.
 *
 * Sustituye a las pruebas del lector de código de barras: un restaurante no
 * escanea platos, se los llama por su nombre o por su código interno. Lo que
 * se cuida acá es lo mismo que se cuidaba entonces —que preguntar por un
 * código exacto devuelva ESE plato y no una lista donde haya que adivinar—,
 * porque de eso depende que el mostrador agregue al carrito lo correcto
 * cuando el cajero teclea y pulsa Enter.
 */
class BusquedaDelMenuTest extends TestCase
{
    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    public function test_buscar_por_codigo_interno_devuelve_ese_plato(): void
    {
        $p = Producto::activos()->firstOrFail();

        $datos = $this->actingAs($this->cajero())
            ->getJson(route('pos.productos', ['q' => $p->codigo]))->json();

        $this->assertNotEmpty($datos, 'el código interno no encontró nada');
        $this->assertSame($p->id, $datos[0]['id'],
            'el primero de la lista no es el plato del código tecleado');
    }

    /**
     * Si un código no existe, la respuesta tiene que venir VACÍA.
     *
     * Es lo que le permite al mostrador avisar en vez de adivinar. Si acá se
     * devolviera «lo más parecido», el cajero terminaría cobrando otra cosa.
     */
    public function test_un_codigo_que_no_existe_no_devuelve_nada_parecido(): void
    {
        $inventado = 'ZZZ-99999';
        $this->assertSame(0, Producto::where('codigo', $inventado)->count());

        $datos = $this->actingAs($this->cajero())
            ->getJson(route('pos.productos', ['q' => $inventado]))->json();

        $this->assertSame([], $datos,
            'un código inexistente devolvió platos: el mostrador agregaría uno equivocado');
    }
}
