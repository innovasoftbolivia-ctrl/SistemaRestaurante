<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El lector de código de barras.
 *
 * Una pistola no es más que un teclado muy rápido: teclea el código entero en
 * tres milisegundos y manda Enter. Del lado del navegador eso destapó un fallo
 * —la búsqueda espera 250 ms, así que al llegar el Enter la lista en pantalla
 * todavía era la anterior y se agregaba el primer producto que hubiera— y se
 * arregló consultando al servidor antes de decidir.
 *
 * Lo que se cuida acá es la mitad que sí se puede probar desde PHP: que
 * preguntar por un código exacto devuelva ESE producto y no una lista donde
 * haya que adivinar.
 */
class CodigoDeBarrasTest extends TestCase
{
    use DatabaseTransactions;

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function conCodigo(): Producto
    {
        return Producto::activos()->whereNotNull('codigo_barras')->firstOrFail();
    }

    public function test_buscar_por_codigo_de_barras_devuelve_ese_producto(): void
    {
        $p = $this->conCodigo();

        $r = $this->actingAs($this->cajero())
            ->getJson(route('pos.productos', ['q' => $p->codigo_barras]));

        $r->assertOk();
        $datos = $r->json();

        $this->assertNotEmpty($datos, 'el código de barras no encontró nada');
        $this->assertSame($p->id, $datos[0]['id'],
            'el primero de la lista no es el producto del código escaneado');
        $this->assertSame($p->codigo_barras, $datos[0]['codigo_barras'],
            'la respuesta tiene que traer el código, o el mostrador no puede comparar');
    }

    public function test_buscar_por_codigo_interno_devuelve_ese_producto(): void
    {
        $p = $this->conCodigo();

        $datos = $this->actingAs($this->cajero())
            ->getJson(route('pos.productos', ['q' => $p->codigo]))->json();

        $this->assertNotEmpty($datos);
        $this->assertSame($p->id, $datos[0]['id']);
    }

    /**
     * Si un código no existe, la respuesta tiene que venir VACÍA.
     *
     * Es lo que le permite al mostrador avisar en vez de adivinar. Si acá se
     * devolviera «lo más parecido», el cajero terminaría cobrando otra cosa.
     */
    public function test_un_codigo_que_no_existe_no_devuelve_nada_parecido(): void
    {
        $inventado = '0000000000000';
        $this->assertSame(0, Producto::where('codigo_barras', $inventado)->count());

        $datos = $this->actingAs($this->cajero())
            ->getJson(route('pos.productos', ['q' => $inventado]))->json();

        $this->assertSame([], $datos,
            'un código inexistente devolvió productos: el mostrador agregaría uno equivocado');
    }

    /** Dos productos no pueden compartir código: el lector no sabría cuál es. */
    public function test_el_codigo_de_barras_no_se_repite(): void
    {
        $repetidos = DB::table('productos')
            ->select('codigo_barras')
            ->whereNotNull('codigo_barras')
            ->groupBy('codigo_barras')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $repetidos);
    }

    /**
     * Los códigos sembrados tienen que ser EAN-13 de verdad.
     *
     * Un lector real calcula el dígito verificador y descarta el código si no
     * cuadra: con dígitos inventados, la demo no se podría mostrar con una
     * pistola en la mano.
     */
    public function test_los_codigos_sembrados_son_ean13_validos(): void
    {
        $malos = [];

        foreach (Producto::whereNotNull('codigo_barras')->pluck('codigo_barras') as $c) {
            if (strlen($c) !== 13 || ! ctype_digit($c)) {
                $malos[] = "$c (no son 13 dígitos)";

                continue;
            }

            $suma = 0;
            for ($i = 0; $i < 12; $i++) {
                $suma += (int) $c[$i] * ($i % 2 ? 3 : 1);
            }

            if ((int) $c[12] !== (10 - $suma % 10) % 10) {
                $malos[] = "$c (dígito verificador)";
            }
        }

        $this->assertSame([], $malos, 'hay códigos que un lector real rechazaría');
    }
}
