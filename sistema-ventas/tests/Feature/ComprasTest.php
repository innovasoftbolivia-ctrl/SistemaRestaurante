<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\Compras;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * La factura del proveedor, entera.
 *
 * El almacén ya cargaba mercadería de a un producto. Lo que faltaba era el caso
 * habitual: el distribuidor deja una factura de treinta líneas, y cargarla
 * producto por producto son treinta idas y vueltas que además no dejan ningún
 * documento que abrir después para contrastar contra el papel.
 *
 * Lo que estas pruebas defienden, por encima de todo, es que esto NO sea una
 * segunda puerta al stock: cada línea pasa por el mismo `Inventario::ingreso()`
 * de siempre, y el kardex sigue siendo la única verdad sobre las existencias.
 */
class ComprasTest extends TestCase
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

    private function proveedor(): Proveedor
    {
        return Proveedor::activos()->firstOrFail();
    }

    /** Un producto que se vende por unidad y llega en cajas de 24. */
    private function productoEnCajas(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();

        $producto->forceFill([
            'contenido_empaque' => 24,
            'nombre_empaque' => 'Caja',
            'precio_compra' => '4.00',
        ])->save();

        return $producto->fresh();
    }

    private function productoAGranel(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 1))
            ->firstOrFail();

        $producto->forceFill(['contenido_empaque' => null, 'nombre_empaque' => null])->save();

        return $producto->fresh();
    }

    // -------------------------------------------------------------- permisos

    public function test_el_almacenero_y_el_administrador_registran_compras(): void
    {
        foreach ([$this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('compras.index'))->assertOk();
            $this->actingAs($usuario)->get(route('compras.create'))->assertOk();
        }
    }

    /** El cajero cobra; lo que entra por la puerta de atrás no es asunto suyo. */
    public function test_el_cajero_no_entra_a_compras(): void
    {
        $this->actingAs($this->cajero())->get(route('compras.index'))->assertForbidden();
        $this->actingAs($this->cajero())->get(route('compras.create'))->assertForbidden();

        $this->actingAs($this->cajero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [['producto_id' => $this->productoEnCajas()->id, 'cantidad' => 1, 'costo_unitario' => 1]],
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------------- registro

    public function test_una_compra_carga_todas_sus_lineas_de_una_vez(): void
    {
        $enCajas = $this->productoEnCajas();
        $aGranel = $this->productoAGranel();

        $antesCajas = (float) $enCajas->stock_actual;
        $antesGranel = (float) $aGranel->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'documento_externo' => 'F001-00777',
                'lineas' => [
                    // 3 cajas de 24 más 5 sueltas = 77
                    ['producto_id' => $enCajas->id, 'empaques' => 3, 'sueltas' => 5, 'costo_unitario' => '4.00'],
                    ['producto_id' => $aGranel->id, 'cantidad' => '12.5', 'costo_unitario' => '6.00'],
                ],
            ])
            ->assertRedirect();

        $compra = Compra::where('documento_externo', 'F001-00777')->firstOrFail();

        $this->assertSame(2, $compra->detalle->count());
        $this->assertSame($antesCajas + 77, (float) $enCajas->fresh()->stock_actual);
        $this->assertSame($antesGranel + 12.5, (float) $aGranel->fresh()->stock_actual);

        // 77 × 4.00 + 12.5 × 6.00
        $this->assertSame(383.0, $compra->total);
    }

    /**
     * El stock no lo mueve la compra: lo mueve el kardex, como siempre. Si esta
     * prueba cayera, existirían dos verdades sobre las existencias.
     */
    public function test_cada_linea_deja_su_movimiento_colgado_de_la_compra(): void
    {
        $producto = $this->productoEnCajas();

        $compra = Compras::registrar(
            usuario: $this->almacenero(),
            proveedor: $this->proveedor(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 48, 'costo_unitario' => 4.0, 'detalle' => '2 cajas de 24']],
            documentoExterno: 'F001-00778',
        );

        $movimiento = MovimientoInventario::where('compra_id', $compra->id)->firstOrFail();

        $this->assertSame('ENTRADA', $movimiento->tipo);
        $this->assertSame('COMPRA', $movimiento->origen);
        $this->assertSame($compra->proveedor_id, $movimiento->proveedor_id);
        $this->assertSame('F001-00778', $movimiento->documento_externo);
        $this->assertSame('2 cajas de 24', $movimiento->motivo);
        $this->assertSame('48.000', $movimiento->cantidad);
        $this->assertSame(1, $compra->movimientos()->count());
    }

    /** El desglose se guarda como en las otras dos puertas: lo arma el servidor. */
    public function test_el_kardex_cuenta_las_cajas_que_llegaron(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [['producto_id' => $producto->id, 'empaques' => 3, 'sueltas' => 5, 'costo_unitario' => '4.00']],
            ])
            ->assertRedirect();

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('3 cajas de 24 + 5 sueltas', $movimiento->motivo);
    }

    public function test_la_compra_queda_en_la_bitacora(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => '4.00']],
            ]);

        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $this->almacenero()->id,
            'accion' => 'COMPRA_REGISTRADA',
            'entidad' => 'compras',
        ]);
    }

    /** El costo de la línea puede volverse el costo de referencia del producto. */
    public function test_una_linea_puede_actualizar_el_costo_del_producto(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [[
                    'producto_id' => $producto->id,
                    'cantidad' => 24,
                    'costo_unitario' => '4.50',
                    'actualizar_costo' => '1',
                ]],
            ])
            ->assertRedirect();

        $this->assertSame('4.50', $producto->fresh()->precio_compra);
    }

    public function test_sin_marcar_la_casilla_el_costo_del_producto_no_se_toca(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [[
                    'producto_id' => $producto->id,
                    'cantidad' => 24,
                    'costo_unitario' => '9.00',
                    'actualizar_costo' => '0',
                ]],
            ])
            ->assertRedirect();

        $this->assertSame('4.00', $producto->fresh()->precio_compra);
    }

    // ------------------------------------------------------------ lo que no

    public function test_una_compra_sin_lineas_se_rechaza(): void
    {
        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), ['proveedor_id' => $this->proveedor()->id, 'lineas' => []])
            ->assertSessionHasErrors('lineas');
    }

    public function test_una_compra_sin_proveedor_se_rechaza(): void
    {
        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'lineas' => [['producto_id' => $this->productoEnCajas()->id, 'cantidad' => 1, 'costo_unitario' => 1]],
            ])
            ->assertSessionHasErrors('proveedor_id');
    }

    /** La unidad manda aquí también: no entran 2,5 gaseosas. */
    public function test_media_unidad_de_algo_entero_se_rechaza(): void
    {
        $producto = $this->productoEnCajas();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('compras.store'), [
                'proveedor_id' => $this->proveedor()->id,
                'lineas' => [['producto_id' => $producto->id, 'cantidad' => '2.5', 'costo_unitario' => '4.00']],
            ])
            ->assertSessionHasErrors();

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
    }

    /**
     * O entra la factura entera o no entra nada. Una compra a medias —diez
     * líneas cargadas y veinte no— sería peor que no haberla cargado, porque
     * nadie sabría dónde se cortó.
     */
    public function test_si_una_linea_falla_no_entra_ninguna(): void
    {
        $bueno = $this->productoEnCajas();
        $malo = $this->productoAGranel();
        $malo->forceFill(['activo' => 0])->save();

        $antes = (float) $bueno->stock_actual;
        $comprasAntes = Compra::count();

        try {
            Compras::registrar(
                usuario: $this->almacenero(),
                proveedor: $this->proveedor(),
                lineas: [
                    ['producto_id' => $bueno->id, 'cantidad' => 10, 'costo_unitario' => 4.0],
                    ['producto_id' => $malo->id, 'cantidad' => 5, 'costo_unitario' => 6.0],
                ],
            );
            $this->fail('La compra debió fallar por el producto descatalogado.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame($antes, (float) $bueno->fresh()->stock_actual);
        $this->assertSame($comprasAntes, Compra::count());
    }

    // ------------------------------------------------------------- pantallas

    public function test_la_ficha_de_la_compra_muestra_sus_lineas_y_su_total(): void
    {
        $producto = $this->productoEnCajas();

        $compra = Compras::registrar(
            usuario: $this->almacenero(),
            proveedor: $this->proveedor(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 24, 'costo_unitario' => 4.0]],
            documentoExterno: 'F001-00779',
        );

        $this->actingAs($this->almacenero())
            ->get(route('compras.show', $compra))
            ->assertOk()
            ->assertSee('F001-00779')
            ->assertSee($producto->nombre);
    }

    public function test_el_buscador_de_lineas_devuelve_el_empaque_del_producto(): void
    {
        $producto = $this->productoEnCajas();

        $respuesta = $this->actingAs($this->almacenero())
            ->getJson(route('compras.productos', ['q' => $producto->codigo]))
            ->assertOk()
            ->json();

        $this->assertSame($producto->id, $respuesta[0]['id']);
        // `assertEquals` y no `assertSame`: al pasar por JSON, un 24.0 vuelve
        // como entero y el tipo exacto aquí no dice nada.
        $this->assertEquals(24, $respuesta[0]['contenido']);
        $this->assertSame('caja', $respuesta[0]['empaque']);
        $this->assertSame('cajas', $respuesta[0]['empaquePlural']);
    }

    // ------------------------------------------------------------ alta rápida

    /**
     * El producto que llega hoy por primera vez es el caso normal de una
     * compra, no la excepción. Mandar a la persona al catálogo y de vuelta le
     * costaría todas las líneas que ya tecleó, así que el alta va por JSON
     * desde la misma pantalla — con la misma validación de siempre.
     */
    public function test_se_puede_dar_de_alta_un_producto_sin_salir_de_la_compra(): void
    {
        $respuesta = $this->actingAs($this->almacenero())
            ->postJson(route('productos.store'), [
                'nombre' => 'Fideo de prueba 400 g',
                'categoria_id' => Categoria::first()->id,
                'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->firstOrFail()->id,
                'viene_en_empaque' => 1,
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => 20,
                'precio_compra' => '3.50',
                'precio_venta' => '5.00',
                'stock_minimo' => 10,
                'stock_inicial' => 0,
            ])
            ->assertCreated()
            ->json();

        // Vuelve listo para usarse como línea, con su empaque ya resuelto.
        $this->assertSame('Fideo de prueba 400 g', $respuesta['nombre']);
        $this->assertEquals(20, $respuesta['contenido']);
        $this->assertSame('caja', $respuesta['empaque']);
        $this->assertEquals(0, $respuesta['stock']);
    }

    /**
     * Nace con stock cero a propósito: las unidades las pone la línea de la
     * compra que se está cargando. Cargarlas también en el alta las contaría
     * dos veces.
     */
    public function test_el_producto_creado_desde_la_compra_nace_sin_stock(): void
    {
        $this->actingAs($this->almacenero())
            ->postJson(route('productos.store'), [
                'nombre' => 'Fideo de prueba 400 g',
                'categoria_id' => Categoria::first()->id,
                'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->firstOrFail()->id,
                'precio_compra' => '3.50',
                'precio_venta' => '5.00',
                'stock_minimo' => 0,
                'stock_inicial' => 0,
            ])
            ->assertCreated();

        $producto = Producto::where('nombre', 'Fideo de prueba 400 g')->firstOrFail();

        $this->assertSame('0.000', $producto->stock_actual);
        $this->assertSame(0, $producto->movimientos()->count());
    }

    /** Sin código escrito, el correlativo lo pone el sistema. */
    public function test_un_producto_sin_codigo_recibe_el_siguiente_correlativo(): void
    {
        $respuesta = $this->actingAs($this->almacenero())
            ->postJson(route('productos.store'), [
                'nombre' => 'Fideo de prueba 400 g',
                'categoria_id' => Categoria::first()->id,
                'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->firstOrFail()->id,
                'precio_compra' => '1.00',
                'precio_venta' => '2.00',
                'stock_minimo' => 0,
            ])
            ->assertCreated()
            ->json();

        $this->assertMatchesRegularExpression('/^P-\d{4}$/', $respuesta['codigo']);
    }

    public function test_se_puede_dar_de_alta_un_proveedor_sin_salir_de_la_compra(): void
    {
        $respuesta = $this->actingAs($this->almacenero())
            ->postJson(route('proveedores.store'), [
                'razon_social' => 'Distribuidora de prueba SRL',
                'documento' => '9988776655',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('Distribuidora de prueba SRL', $respuesta['razon_social']);
        $this->assertDatabaseHas('proveedores', ['documento' => '9988776655']);
    }

    /**
     * Responder en JSON no abre ninguna puerta: el permiso es el mismo de
     * siempre, y por eso los atajos solo se pintan a quien puede usarlos.
     */
    public function test_el_cajero_no_da_de_alta_productos_ni_por_json(): void
    {
        $this->actingAs($this->cajero())
            ->postJson(route('productos.store'), ['nombre' => 'Colado'])
            ->assertForbidden();

        $this->actingAs($this->cajero())
            ->postJson(route('proveedores.store'), ['razon_social' => 'Colado'])
            ->assertForbidden();
    }

    /** La vía de una línea sigue existiendo y no cuelga de ninguna compra. */
    public function test_el_ingreso_suelto_del_almacen_sigue_sin_compra_detras(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 5])
            ->assertRedirect();

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('COMPRA', $movimiento->origen);
        $this->assertNull($movimiento->compra_id);
    }
}
