<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El almacén como módulo propio.
 *
 * Las operaciones ya existían dentro de la ficha del producto, pero para
 * llegar ahí hay que saber de antemano cuál es. El almacenero trabaja al
 * revés: le llega la mercadería y tiene que encontrarla. Estas pruebas cubren
 * esa segunda puerta, y sobre todo que no se haya aflojado ninguna regla al
 * abrirla.
 */
class InventarioModuloTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function producto(): Producto
    {
        return Producto::activos()->firstOrFail();
    }

    // -------------------------------------------------------------- permisos

    public function test_el_almacenero_y_el_administrador_entran_al_inventario(): void
    {
        foreach ([$this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('inventario.index'))->assertOk();
            $this->actingAs($usuario)->get(route('inventario.movimientos'))->assertOk();
        }
    }

    /** El cajero cobra; el stock no es asunto suyo. */
    public function test_el_cajero_no_entra_al_inventario(): void
    {
        $this->actingAs($this->cajero())->get(route('inventario.index'))->assertForbidden();
        $this->actingAs($this->cajero())->get(route('inventario.movimientos'))->assertForbidden();
    }

    public function test_el_cajero_no_puede_cargar_ni_ajustar_stock(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->cajero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 10])
            ->assertForbidden();

        $this->actingAs($this->cajero())
            ->post(route('inventario.ajuste'), [
                'producto_id' => $producto->id,
                'stock_contado' => 999,
                'motivo' => 'no debería poder',
            ])
            ->assertForbidden();

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
    }

    // -------------------------------------------------------------- ingresos

    public function test_el_ingreso_suma_al_stock_y_deja_el_movimiento(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'cantidad' => 12,
                'documento_externo' => 'F001-00042',
                'motivo' => 'Reposición de prueba',
            ])
            ->assertRedirect();

        $this->assertSame($antes + 12, (float) $producto->fresh()->stock_actual);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->first();

        $this->assertSame('ENTRADA', $movimiento->tipo);
        $this->assertSame('COMPRA', $movimiento->origen);
        $this->assertSame('F001-00042', $movimiento->documento_externo);
        $this->assertSame($antes, (float) $movimiento->stock_anterior);
        $this->assertSame($antes + 12, (float) $movimiento->stock_resultante);
        $this->assertSame($this->almacenero()->id, $movimiento->usuario_id);
    }

    public function test_el_ingreso_queda_en_la_bitacora(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 3]);

        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $this->almacenero()->id,
            'accion' => 'INVENTARIO_INGRESO',
            'entidad' => 'productos',
            'entidad_id' => $producto->id,
        ]);
    }

    public function test_no_se_ingresa_una_cantidad_de_cero_o_negativa(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        foreach ([0, -5] as $cantidad) {
            $this->actingAs($this->almacenero())
                ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => $cantidad])
                ->assertSessionHasErrors('cantidad');
        }

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
    }

    /**
     * La unidad manda: no se ingresan 2,5 gaseosas. Es la misma regla que en la
     * ficha del producto, y hay que comprobarla también por esta puerta.
     */
    public function test_una_unidad_entera_no_admite_decimales(): void
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 2.5])
            ->assertSessionHasErrors('cantidad');
    }

    public function test_no_se_carga_stock_a_un_producto_descatalogado(): void
    {
        $producto = $this->producto();
        $producto->forceFill(['activo' => 0])->save();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 5])
            ->assertSessionHasErrors('producto_id');
    }

    // --------------------------------------------------------------- ajustes

    public function test_el_ajuste_lleva_el_stock_al_valor_contado(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;
        $contado = $antes + 7;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ajuste'), [
                'producto_id' => $producto->id,
                'stock_contado' => $contado,
                'motivo' => 'Conteo físico de prueba',
            ])
            ->assertRedirect();

        $this->assertSame($contado, (float) $producto->fresh()->stock_actual);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->first();

        $this->assertSame('AJUSTE', $movimiento->tipo);
        $this->assertSame('Conteo físico de prueba', $movimiento->motivo);
    }

    public function test_el_ajuste_sin_motivo_se_rechaza(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ajuste'), [
                'producto_id' => $producto->id,
                'stock_contado' => $antes + 3,
            ])
            ->assertSessionHasErrors('motivo');

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
    }

    /** Contar lo mismo que dice el sistema no es un movimiento: no ensucia el kardex. */
    public function test_un_conteo_que_coincide_no_registra_movimiento(): void
    {
        $producto = $this->producto();
        $antes = MovimientoInventario::where('producto_id', $producto->id)->count();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ajuste'), [
                'producto_id' => $producto->id,
                'stock_contado' => (float) $producto->stock_actual,
                'motivo' => 'Conteo sin diferencia',
            ])
            ->assertRedirect();

        $this->assertSame($antes, MovimientoInventario::where('producto_id', $producto->id)->count());
    }

    // ---------------------------------------------------------- movimientos

    public function test_el_listado_filtra_por_producto(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'cantidad' => 4,
                'motivo' => 'Marca para el filtro',
            ]);

        $this->actingAs($this->almacenero())
            ->get(route('inventario.movimientos', ['producto' => $producto->id]))
            ->assertOk()
            ->assertSee('Marca para el filtro');
    }

    public function test_el_listado_filtra_por_tipo_de_movimiento(): void
    {
        $this->actingAs($this->almacenero())
            ->get(route('inventario.movimientos', ['origen' => 'AJUSTE']))
            ->assertOk();

        // Un origen que no existe en el ENUM no puede colarse a la consulta.
        $this->actingAs($this->almacenero())
            ->get(route('inventario.movimientos', ['origen' => 'INVENTADO']))
            ->assertOk();
    }

    // ------------------------------------------- el puente desde el catálogo

    /**
     * Quien intenta cargar de nuevo un producto que ya existe casi siempre
     * quería sumarle stock. Antes el aviso decía «ya existe» y ahí terminaba;
     * ahora nombra al producto y manda al inventario.
     */
    public function test_el_codigo_repetido_nombra_al_producto_y_manda_al_inventario(): void
    {
        $existente = Producto::firstOrFail();

        $respuesta = $this->actingAs($this->almacenero())->post(route('productos.store'), [
            'categoria_id' => $existente->categoria_id,
            'unidad_medida_id' => $existente->unidad_medida_id,
            'codigo' => $existente->codigo,
            'nombre' => 'Intento duplicado',
            'precio_compra' => '10.00',
            'precio_venta' => '15.00',
            'stock_minimo' => '5',
        ]);

        $respuesta->assertSessionHasErrors('codigo');
        $respuesta->assertSessionHas('producto_duplicado', fn ($duplicado) => $duplicado['id'] === $existente->id
            && $duplicado['codigo'] === $existente->codigo);

        $error = session('errors')->first('codigo');
        $this->assertStringContainsString($existente->nombre, $error);
        $this->assertStringContainsString('Inventario', $error);

        $this->assertDatabaseMissing('productos', ['nombre' => 'Intento duplicado']);
    }

    /** El código de barras repetido tiene que llevar al mismo sitio. */
    public function test_el_codigo_de_barras_repetido_tambien_manda_al_inventario(): void
    {
        $existente = Producto::whereNotNull('codigo_barras')->firstOrFail();

        $respuesta = $this->actingAs($this->almacenero())->post(route('productos.store'), [
            'categoria_id' => $existente->categoria_id,
            'unidad_medida_id' => $existente->unidad_medida_id,
            'codigo' => 'P-9999',
            'codigo_barras' => $existente->codigo_barras,
            'nombre' => 'Otro intento duplicado',
            'precio_compra' => '10.00',
            'precio_venta' => '15.00',
            'stock_minimo' => '5',
        ]);

        $respuesta->assertSessionHasErrors('codigo_barras');
        $this->assertStringContainsString($existente->nombre, session('errors')->first('codigo_barras'));
    }

    /**
     * El aviso solo aparece cuando de verdad hay un choque. Sin esta prueba,
     * un `where` con un grupo vacío devolvería el primer producto del catálogo
     * y el sistema acusaría de duplicado a cualquier alta legítima.
     */
    public function test_un_producto_nuevo_sin_choque_no_dispara_el_aviso(): void
    {
        $respuesta = $this->actingAs($this->almacenero())->post(route('productos.store'), [
            'categoria_id' => Categoria::firstOrFail()->id,
            'unidad_medida_id' => UnidadMedida::firstOrFail()->id,
            'codigo' => 'P-9911',
            'nombre' => 'Producto realmente nuevo',
            'precio_compra' => '10.00',
            'precio_venta' => '15.00',
            'stock_minimo' => '5',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertSessionMissing('producto_duplicado');
        $this->assertDatabaseHas('productos', ['codigo' => 'P-9911']);
    }

    /** Editar un producto sin tocarle el código no es un duplicado de sí mismo. */
    public function test_editar_un_producto_conservando_su_codigo_no_dispara_el_aviso(): void
    {
        $producto = Producto::firstOrFail();

        $this->actingAs($this->almacenero())
            ->put(route('productos.update', $producto), [
                'categoria_id' => $producto->categoria_id,
                'unidad_medida_id' => $producto->unidad_medida_id,
                'codigo' => $producto->codigo,
                'codigo_barras' => $producto->codigo_barras,
                'nombre' => $producto->nombre.' editado',
                'precio_compra' => (string) $producto->precio_compra,
                'precio_venta' => (string) $producto->precio_venta,
                'stock_minimo' => (string) $producto->stock_minimo,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('producto_duplicado');
    }

    // ------------------------------------------------------------------ menú

    public function test_el_inventario_aparece_en_el_menu_de_quien_puede_usarlo(): void
    {
        $this->actingAs($this->almacenero())
            ->get(route('inventario.index'))
            ->assertOk()
            ->assertSee(route('inventario.movimientos'));

        // El cajero ni siquiera lo ve ofrecido.
        $this->actingAs($this->cajero())
            ->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee(route('inventario.index'));
    }
}
