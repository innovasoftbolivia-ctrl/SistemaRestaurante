<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Inventario;
use App\Services\TomasInventario;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Toma de inventario: contar el local entero y ajustar las diferencias de una vez.
 *
 * La regla que más importa es la del cierre: se aplica la DIFERENCIA contada
 * sobre el stock de ese momento, porque el local sigue vendiendo mientras se
 * cuenta. Por eso cada prueba mira el stock antes y después, y no un número fijo.
 */
class TomaInventarioTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function producto(string $codigo): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function stock(string $codigo): float
    {
        return (float) $this->producto($codigo)->stock_actual;
    }

    private function abrir(?int $categoriaId = null): TomaInventario
    {
        $this->actingAs($this->admin());

        return TomasInventario::abrir($this->admin(), $categoriaId);
    }

    private function linea(TomaInventario $toma, string $codigo): TomaInventarioDetalle
    {
        return TomaInventarioDetalle::where('toma_id', $toma->id)
            ->where('producto_id', $this->producto($codigo)->id)
            ->firstOrFail();
    }

    private function contar(TomaInventario $toma, string $codigo, ?float $cantidad): TomaInventarioDetalle
    {
        return TomasInventario::contar($this->linea($toma, $codigo), $this->admin(), $cantidad);
    }

    private function vender(string $codigo, float $cantidad): void
    {
        $sesion = Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), 100);

        Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $this->admin(),
            lineas: [['producto_id' => $this->producto($codigo)->id, 'cantidad' => $cantidad]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // =============================================================== abrir

    public function test_abrir_arma_la_lista_de_todos_los_productos_activos_sin_contar(): void
    {
        $toma = $this->abrir();

        $this->assertSame(Producto::activos()->count(), $toma->lineas()->count());
        $this->assertSame(0, $toma->lineas()->whereNotNull('contado')->count());
        $this->assertSame('Toda la tienda', $toma->alcance);
        $this->assertDatabaseHas('auditoria', ['accion' => 'TOMA_INVENTARIO_ABIERTA', 'entidad_id' => $toma->id]);
    }

    public function test_por_categoria_solo_entran_los_de_esa_categoria(): void
    {
        $categoria = $this->producto('P-0006')->categoria_id;
        $toma = $this->abrir($categoria);

        $this->assertSame(Producto::activos()->where('categoria_id', $categoria)->count(), $toma->lineas()->count());
        $this->assertSame(0, $toma->lineas()->whereHas('producto', fn ($q) => $q->where('categoria_id', '<>', $categoria))->count());
    }

    public function test_no_puede_haber_dos_tomas_abiertas_a_la_vez(): void
    {
        $this->abrir();

        $this->expectExceptionMessage('Ya hay una toma de inventario abierta');
        $this->abrir();
    }

    // ============================================================== cierre

    /**
     * Se contaron 2 menos de lo que decía el sistema y después se vendió 1. El
     * cierre deja stock − 1 − 2: aplicar el número contado borraría la venta.
     */
    public function test_el_cierre_respeta_lo_vendido_despues_de_contar(): void
    {
        $antes = $this->stock('P-0004');
        $toma = $this->abrir();

        $linea = $this->contar($toma, 'P-0004', $antes - 2);
        $this->assertSame(-2.0, (float) $linea->diferencia);

        $this->vender('P-0004', 1);
        $this->assertSame($antes - 1, $this->stock('P-0004'));

        TomasInventario::cerrar($toma, $this->admin());

        $this->assertSame($antes - 1 - 2, $this->stock('P-0004'));
    }

    public function test_el_sobrante_suma_y_queda_en_el_kardex(): void
    {
        $antes = $this->stock('P-0004');
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', $antes + 3);

        $resumen = TomasInventario::cerrar($toma, $this->admin());

        $this->assertSame($antes + 3, $this->stock('P-0004'));
        $this->assertSame(1, $resumen['ajustados']);

        $movimiento = MovimientoInventario::findOrFail($this->linea($toma, 'P-0004')->movimiento_id);
        $this->assertSame('AJUSTE', $movimiento->origen);
        $this->assertSame("Toma de inventario #{$toma->id}", $movimiento->motivo);
        $this->assertSame('CERRADA', $toma->fresh()->estado);
        $this->assertDatabaseHas('auditoria', ['accion' => 'TOMA_INVENTARIO_CERRADA', 'entidad_id' => $toma->id]);
    }

    /** No contar un producto no es contarlo en cero. */
    public function test_lo_que_no_se_conto_queda_como_estaba(): void
    {
        $sinContar = $this->stock('P-0002');
        $cuadra = $this->stock('P-0006');
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', $this->stock('P-0004') - 1);
        $this->contar($toma, 'P-0006', $cuadra);

        $resumen = TomasInventario::cerrar($toma, $this->admin());

        $this->assertSame($sinContar, $this->stock('P-0002'));
        $this->assertSame($cuadra, $this->stock('P-0006'));
        $this->assertSame(2, $resumen['contados']);
        $this->assertSame(1, $resumen['ajustados']);
        $this->assertNull($this->linea($toma, 'P-0006')->movimiento_id, 'lo que cuadra no genera ajuste');
    }

    public function test_el_resumen_valoriza_las_diferencias_al_costo(): void
    {
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', $this->stock('P-0004') - 2);   // 2 × 2,80
        $this->contar($toma, 'P-0006', $this->stock('P-0006') + 5);   // 5 × 0,90

        $resumen = TomasInventario::resumen($toma);

        $this->assertSame(2, $resumen['con_diferencia']);
        $this->assertSame(round(2 * (float) $this->producto('P-0004')->precio_compra, 2), $resumen['faltante']);
        $this->assertSame(round(5 * (float) $this->producto('P-0006')->precio_compra, 2), $resumen['sobrante']);
    }

    public function test_volver_a_contar_reemplaza_y_vacio_deja_sin_contar(): void
    {
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', 1);
        $this->contar($toma, 'P-0004', 5);
        $this->assertSame('5.000', $this->linea($toma, 'P-0004')->contado);

        $linea = $this->contar($toma, 'P-0004', null);
        $this->assertNull($linea->contado);
        $this->assertNull($linea->stock_sistema);
    }

    public function test_la_correccion_nunca_deja_stock_negativo(): void
    {
        $this->actingAs($this->admin());
        $producto = $this->producto('P-0004');

        Inventario::corregir($producto, -((float) $producto->stock_actual + 5), 'Prueba');

        $this->assertSame(0.0, $this->stock('P-0004'));
    }

    public function test_no_se_cierra_una_toma_sin_ningun_conteo(): void
    {
        $toma = $this->abrir();

        $this->expectExceptionMessage('No se contó ningún producto');
        TomasInventario::cerrar($toma, $this->admin());
    }

    public function test_cancelar_no_toca_el_stock(): void
    {
        $antes = $this->stock('P-0004');
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', 0);

        TomasInventario::cancelar($toma, $this->admin());

        $this->assertSame($antes, $this->stock('P-0004'));
        $this->assertSame('CANCELADA', $toma->fresh()->estado);
        $this->abrir();   // y se puede empezar otra
    }

    public function test_una_toma_cerrada_ya_no_admite_conteos(): void
    {
        $toma = $this->abrir();
        $this->contar($toma, 'P-0004', 1);
        TomasInventario::cerrar($toma, $this->admin());

        try {
            $this->contar($toma, 'P-0004', 2);
            $this->fail('se cambió un conteo de una toma cerrada');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya está cerrada', $e->getMessage());
        }
    }

    // ============================================================ pantallas

    public function test_el_almacenero_abre_cuenta_y_cierra_desde_la_pantalla(): void
    {
        $antes = $this->stock('P-0004');
        $this->actingAs($this->almacenero());

        $this->post(route('tomas.store'), ['observacion' => 'Fin de mes'])->assertRedirect();
        $toma = TomaInventario::where('estado', 'ABIERTA')->firstOrFail();

        $this->get(route('tomas.show', $toma))->assertOk()->assertSee('Fin de mes');
        $this->get(route('tomas.show', [$toma, 'buscar' => 'P-0004']))->assertOk()->assertSee('contado-'.$this->linea($toma, 'P-0004')->id, false);

        $this->postJson(route('tomas.contar', [$toma, $this->linea($toma, 'P-0004')]), ['contado' => $antes - 4])
            ->assertOk()
            ->assertJson(['contado' => $antes - 4, 'sistema' => $antes, 'diferencia' => -4])
            ->assertJsonPath('resumen.contados', 1);

        $this->post(route('tomas.cerrar', $toma))
            ->assertRedirect(route('tomas.show', $toma))
            ->assertSessionHas('exito');

        $this->assertSame($antes - 4, $this->stock('P-0004'));
        $this->get(route('tomas.imprimir', $toma))->assertOk()->assertSee('Resultado de la toma de inventario');
    }

    public function test_lo_que_se_vende_por_unidad_no_se_cuenta_con_decimales(): void
    {
        $toma = $this->abrir();

        $this->postJson(route('tomas.contar', [$toma, $this->linea($toma, 'P-0004')]), ['contado' => 2.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contado');

        // El arroz se vende por kilo: ahí sí.
        $this->postJson(route('tomas.contar', [$toma, $this->linea($toma, 'P-0001')]), ['contado' => 2.5])->assertOk();
    }

    public function test_una_linea_de_otra_toma_no_se_puede_contar(): void
    {
        $vieja = $this->abrir();
        $lineaVieja = $this->linea($vieja, 'P-0004');
        TomasInventario::cancelar($vieja, $this->admin());
        $nueva = $this->abrir();

        $this->postJson(route('tomas.contar', [$nueva, $lineaVieja]), ['contado' => 1])->assertNotFound();
        $this->assertNull($lineaVieja->fresh()->contado);
    }

    public function test_el_cajero_no_entra_a_la_toma_de_inventario(): void
    {
        $toma = $this->abrir();
        $this->actingAs($this->cajero());

        $this->get(route('tomas.index'))->assertForbidden();
        $this->get(route('tomas.show', $toma))->assertForbidden();
        $this->post(route('tomas.store'))->assertForbidden();
        $this->postJson(route('tomas.contar', [$toma, $this->linea($toma, 'P-0004')]), ['contado' => 1])->assertForbidden();
        $this->post(route('tomas.cerrar', $toma))->assertForbidden();
    }

    /** Se cuenta lo que hay en el estante, no se confirma el número del sistema. */
    public function test_la_planilla_para_contar_no_muestra_el_stock_del_sistema(): void
    {
        $toma = $this->abrir();

        $this->get(route('tomas.imprimir', $toma))
            ->assertOk()
            ->assertSee('Planilla de conteo')
            ->assertSee($this->producto('P-0004')->nombre)
            ->assertDontSee('>Sistema<', false);
    }
}
