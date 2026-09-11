<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Inventario;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogoTest extends TestCase
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

    private function producto(string $codigo = 'P-0001'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    /** @return array<string, mixed> */
    /**
     * El catálogo arranca mostrando lo vigente.
     *
     * Antes mostraba todo, y no se notaba porque no había descatalogados. El
     * día que hubo 152, el listado eran cientos de filas muertas antes de
     * llegar a lo que se vende hoy.
     */
    public function test_el_catalogo_muestra_solo_lo_vigente_por_omision(): void
    {
        $vigente = $this->producto();
        $cesado = Producto::where('id', '<>', $vigente->id)->firstOrFail();
        $cesado->forceFill(['activo' => 0])->save();

        $this->actingAs($this->admin())
            ->get(route('productos.index', ['buscar' => $cesado->codigo]))
            ->assertOk()
            ->assertDontSee($cesado->nombre);
    }

    /** Pero se llega a ellos: tienen historia detrás y no se borran. */
    public function test_los_descatalogados_se_pueden_ver_cuando_se_piden(): void
    {
        $cesado = $this->producto();
        $cesado->forceFill(['activo' => 0])->save();

        $this->actingAs($this->admin())
            ->get(route('productos.index', ['estado' => 'CESADO', 'buscar' => $cesado->codigo]))
            ->assertOk()
            ->assertSee($cesado->nombre);

        // Y «Todos» —el estado vacío— los sigue trayendo junto al resto.
        $this->actingAs($this->admin())
            ->get(route('productos.index', ['estado' => '', 'buscar' => $cesado->codigo]))
            ->assertOk()
            ->assertSee($cesado->nombre);
    }

    private function datosProducto(array $sobrescribir = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9001',
            'codigo_barras' => '7750009999991',
            'nombre' => 'Producto de prueba',
            'precio_compra' => '5.00',
            'precio_venta' => '8.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '10',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    // ------------------------------------------------------------- permisos

    public function test_el_cajero_no_entra_al_catalogo(): void
    {
        foreach (['/productos', '/categorias', '/unidades', '/proveedores'] as $ruta) {
            $this->actingAs($this->cajero())->get($ruta)->assertForbidden();
        }
    }

    /** El almacenero gestiona el catálogo aunque no toque empleados ni cuentas. */
    public function test_el_almacenero_entra_al_catalogo_pero_no_a_seguridad(): void
    {
        foreach (['/productos', '/productos/nuevo', '/categorias', '/unidades', '/proveedores'] as $ruta) {
            $this->actingAs($this->almacenero())->get($ruta)->assertOk();
        }

        $this->actingAs($this->almacenero())->get('/usuarios')->assertForbidden();
        $this->actingAs($this->almacenero())->get('/empleados')->assertForbidden();
    }

    public function test_las_pantallas_del_producto_responden(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->admin())->get("/productos/{$producto->id}")->assertOk();
        $this->actingAs($this->admin())->get("/productos/{$producto->id}/editar")->assertOk();
    }

    // ------------------------------------------------------------- productos

    public function test_se_crea_un_producto_con_su_stock_inicial(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto(['stock_inicial' => '25']))
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        $this->assertSame('25.000', $producto->stock_actual);

        // El stock no se escribe a mano: tiene que haber dejado rastro en el kardex.
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'origen' => 'INICIAL',
            'stock_anterior' => '0.000',
            'stock_resultante' => '25.000',
        ]);
    }

    public function test_un_producto_sin_stock_inicial_no_genera_movimiento(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto(['stock_inicial' => '0']))
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        $this->assertSame(0, $producto->movimientos()->count());
    }

    public function test_no_se_repite_el_codigo_interno(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto(['codigo' => 'P-0001']))
            ->assertSessionHasErrors('codigo');
    }

    public function test_no_se_repite_el_codigo_de_barras(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto(['codigo_barras' => '7750001000011']))
            ->assertSessionHasErrors('codigo_barras');
    }

    public function test_el_cambio_de_precio_queda_auditado(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->admin())->put("/productos/{$producto->id}", $this->datosProducto([
            'codigo' => $producto->codigo,
            'codigo_barras' => $producto->codigo_barras,
            'nombre' => $producto->nombre,
            'unidad_medida_id' => $producto->unidad_medida_id,
            'precio_venta' => '4.50',
        ]))->assertRedirect();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'CAMBIO_PRECIO',
            'entidad' => 'productos',
            'entidad_id' => $producto->id,
        ]);
    }

    public function test_un_producto_con_movimientos_se_descataloga_en_vez_de_borrarse(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->admin())->delete("/productos/{$producto->id}")->assertRedirect('/productos');

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'activo' => 0]);
    }

    /**
     * Las cifras de cabecera se calculan en SQL. Con los datos de ejemplo
     * ningún producto está bajo su mínimo, y el resumen tiene que decirlo.
     */
    public function test_el_resumen_del_catalogo_cuenta_bien_las_alertas(): void
    {
        $esperado = Producto::activos()->bajoMinimo()->count();

        $respuesta = $this->actingAs($this->admin())->get('/productos')->assertOk();

        $this->assertSame($esperado, $respuesta->viewData('resumen')['bajo_minimo']);
        $this->assertSame(Producto::activos()->count(), $respuesta->viewData('resumen')['total']);
    }

    public function test_el_resumen_detecta_un_producto_bajo_su_minimo(): void
    {
        $producto = $this->producto();
        $antes = $this->actingAs($this->admin())->get('/productos')->viewData('resumen')['bajo_minimo'];

        $producto->update(['stock_minimo' => (float) $producto->stock_actual + 1]);

        $this->assertSame(
            $antes + 1,
            $this->actingAs($this->admin())->get('/productos')->viewData('resumen')['bajo_minimo'],
        );
    }

    // ---------------------------------------------------------------- precios

    /** El precio de estante sale de la base más el impuesto vigente. */
    public function test_el_precio_de_estante_agrega_el_impuesto(): void
    {
        $producto = $this->producto(); // base 3.81, tasa 0.18

        $this->assertSame(4.50, $producto->precio_estante);
        $this->assertSame(0.61, $producto->margen);
    }

    public function test_un_producto_exonerado_no_lleva_impuesto_en_el_estante(): void
    {
        $producto = $this->producto();
        $producto->afecto_impuesto = false;

        $this->assertSame(3.81, $producto->precio_estante);
    }

    // ------------------------------------------------------------- inventario

    public function test_un_ingreso_suma_stock_y_deja_movimiento(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ingreso", [
                'cantidad' => '30',
                'proveedor_id' => Proveedor::first()->id,
                'documento_externo' => 'F001-00987',
            ])
            ->assertRedirect();

        $this->assertSame($antes + 30, (float) $producto->fresh()->stock_actual);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'origen' => 'COMPRA',
            'documento_externo' => 'F001-00987',
            'usuario_id' => $this->almacenero()->id,
        ]);
    }

    public function test_el_ajuste_calcula_la_diferencia_contra_el_conteo(): void
    {
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ajuste", [
                'stock_contado' => (string) ($antes - 5),
                'motivo' => 'Merma por rotura en el almacén',
            ])
            ->assertRedirect();

        $this->assertSame($antes - 5, (float) $producto->fresh()->stock_actual);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->where('origen', 'AJUSTE')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(-5.0, $movimiento->variacion);
        $this->assertSame('Merma por rotura en el almacén', $movimiento->motivo);
    }

    /** Un ajuste sin explicación es un descuadre sin responsable. */
    public function test_el_ajuste_exige_motivo(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ajuste", ['stock_contado' => '10'])
            ->assertSessionHasErrors('motivo');
    }

    public function test_si_el_conteo_coincide_no_se_registra_movimiento(): void
    {
        $producto = $this->producto();
        $movimientosAntes = $producto->movimientos()->count();

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ajuste", [
                'stock_contado' => (string) (float) $producto->stock_actual,
                'motivo' => 'Conteo físico mensual',
            ])
            ->assertRedirect();

        $this->assertSame($movimientosAntes, $producto->fresh()->movimientos()->count());
    }

    /** Media unidad de jabón no existe: la unidad manda. */
    public function test_una_unidad_sin_decimales_rechaza_cantidades_fraccionarias(): void
    {
        $producto = $this->producto('P-0004'); // unidad UND

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ingreso", ['cantidad' => '2.5'])
            ->assertSessionHasErrors('cantidad');
    }

    public function test_una_unidad_con_decimales_si_acepta_fracciones(): void
    {
        $producto = $this->producto('P-0001'); // unidad KG
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ingreso", ['cantidad' => '2.5'])
            ->assertSessionHasNoErrors();

        $this->assertSame($antes + 2.5, (float) $producto->fresh()->stock_actual);
    }

    public function test_el_ajuste_no_deja_el_stock_en_negativo(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->almacenero())
            ->post("/productos/{$producto->id}/ajuste", [
                'stock_contado' => '-3',
                'motivo' => 'Prueba',
            ])
            ->assertSessionHasErrors('stock_contado');
    }

    /** El almacenero mueve stock; el cajero no. */
    public function test_el_cajero_no_mueve_inventario(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->cajero())
            ->post("/productos/{$producto->id}/ingreso", ['cantidad' => '5'])
            ->assertForbidden();
    }

    public function test_el_servicio_de_inventario_encadena_los_saldos(): void
    {
        $producto = $this->producto();
        $inicial = (float) $producto->stock_actual;

        $this->actingAs($this->admin());

        $entrada = Inventario::ingreso($producto, 10);
        $ajuste = Inventario::ajuste($producto->fresh(), $inicial + 4, 'Conteo físico');

        $this->assertSame($inicial, (float) $entrada->stock_anterior);
        $this->assertSame($inicial + 10, (float) $entrada->stock_resultante);

        // El siguiente movimiento arranca donde terminó el anterior.
        $this->assertSame($inicial + 10, (float) $ajuste->stock_anterior);
        $this->assertSame($inicial + 4, (float) $ajuste->stock_resultante);
    }

    // -------------------------------------------------- catálogos de apoyo

    public function test_se_crea_una_categoria(): void
    {
        $this->actingAs($this->admin())
            ->post('/categorias', ['nombre' => 'Panadería', 'descripcion' => 'Pan del día', 'activo' => 1])
            ->assertRedirect('/categorias');

        $this->assertDatabaseHas('categorias', ['nombre' => 'Panadería']);
    }

    public function test_una_categoria_con_productos_se_desactiva_en_vez_de_borrarse(): void
    {
        $categoria = Categoria::where('nombre', 'Abarrotes')->firstOrFail();

        $this->actingAs($this->admin())->delete("/categorias/{$categoria->id}")->assertRedirect('/categorias');

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'activo' => 0]);
    }

    public function test_una_unidad_en_uso_no_se_elimina(): void
    {
        $unidad = UnidadMedida::where('codigo', 'UND')->firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/unidades/{$unidad->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('unidades_medida', ['id' => $unidad->id]);
    }

    public function test_se_crea_una_unidad_de_medida(): void
    {
        $this->actingAs($this->admin())
            ->post('/unidades', ['codigo' => 'DOC', 'nombre' => 'Docena', 'permite_decimal' => 0])
            ->assertRedirect('/unidades');

        $this->assertDatabaseHas('unidades_medida', ['codigo' => 'DOC']);
    }

    public function test_el_codigo_de_unidad_va_en_mayusculas(): void
    {
        $this->actingAs($this->admin())
            ->post('/unidades', ['codigo' => 'doc', 'nombre' => 'Docena'])
            ->assertSessionHasErrors('codigo');
    }

    public function test_se_crea_un_proveedor(): void
    {
        $this->actingAs($this->admin())->post('/proveedores', [
            'razon_social' => 'Importadora del Sur S.A.C.',
            'documento' => '20100000009',
            'telefono' => '987000999',
            'activo' => 1,
        ])->assertRedirect('/proveedores');

        $this->assertDatabaseHas('proveedores', ['documento' => '20100000009']);
    }

    public function test_no_se_repite_el_documento_del_proveedor(): void
    {
        $this->actingAs($this->admin())->post('/proveedores', [
            'razon_social' => 'Otra empresa',
            'documento' => '20100000001',
        ])->assertSessionHasErrors('documento');
    }

    public function test_un_proveedor_con_productos_se_desactiva_en_vez_de_borrarse(): void
    {
        $proveedor = Proveedor::has('productos')->firstOrFail();

        $this->actingAs($this->admin())->delete("/proveedores/{$proveedor->id}")->assertRedirect('/proveedores');

        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id, 'activo' => 0]);
    }

    // ------------------------------------------------------------ fotos

    public function test_se_guarda_la_foto_de_un_producto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('arroz.jpg', 400, 400),
        ]))->assertRedirect();

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        $this->assertNotNull($producto->imagen);
        Storage::disk('public')->assertExists($producto->imagen);
        $this->assertStringContainsString('productos/', $producto->imagen);
        $this->assertStringContainsString($producto->imagen, $producto->imagen_url);
    }

    /**
     * La dirección de la foto se arma con el host de la petición, no con
     * `APP_URL`: el sistema se abre desde varias máquinas de la red del
     * negocio y las imágenes tienen que pedirse al mismo sitio que la página.
     */
    public function test_la_url_de_la_foto_usa_el_host_desde_el_que_se_entra(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        $respuesta = $this->actingAs($this->admin())
            ->getJson('http://192.168.1.50/pos/productos?q=Producto de prueba')
            ->assertOk();

        $this->assertStringStartsWith('http://192.168.1.50/storage/', $respuesta->json('0.imagen'));
    }

    /** Al reemplazar la foto, la anterior no puede quedarse ocupando disco. */
    public function test_al_cambiar_la_foto_se_borra_la_anterior(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('vieja.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $anterior = $producto->imagen;

        $this->actingAs($this->admin())->put("/productos/{$producto->id}", $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('nueva.jpg'),
        ]))->assertRedirect();

        $nueva = $producto->fresh()->imagen;

        $this->assertNotSame($anterior, $nueva);
        Storage::disk('public')->assertExists($nueva);
        Storage::disk('public')->assertMissing($anterior);
    }

    public function test_se_puede_quitar_la_foto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $archivo = $producto->imagen;

        $this->actingAs($this->admin())->put("/productos/{$producto->id}", $this->datosProducto([
            'quitar_imagen' => 1,
        ]))->assertRedirect();

        $this->assertNull($producto->fresh()->imagen);
        Storage::disk('public')->assertMissing($archivo);
    }

    /** Editar sin tocar la foto la conserva. */
    public function test_editar_sin_subir_nada_conserva_la_foto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $archivo = $producto->imagen;

        $this->actingAs($this->admin())
            ->put("/productos/{$producto->id}", $this->datosProducto(['nombre' => 'Otro nombre']))
            ->assertRedirect();

        $this->assertSame($archivo, $producto->fresh()->imagen);
        Storage::disk('public')->assertExists($archivo);
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->create('lista.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('imagen');

        $this->assertDatabaseMissing('productos', ['codigo' => 'P-9001']);
    }

    public function test_una_imagen_demasiado_pesada_se_rechaza(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('enorme.jpg')->size(3000),
        ]))->assertSessionHasErrors('imagen');
    }

    /** El mostrador necesita la URL de la foto para pintar sus tarjetas. */
    public function test_el_mostrador_recibe_la_foto_del_producto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        $respuesta = $this->actingAs($this->admin())
            ->getJson('/pos/productos?q=Producto de prueba')
            ->assertOk();

        $this->assertSame($producto->imagen_url, $respuesta->json('0.imagen'));
    }

    /**
     * La foto va dentro de un recuadro de medida fija y se acomoda con
     * `object-scale-down`: entra entera sea vertical, apaisada o cuadrada, sin
     * recortarse ni deformarse, y una imagen diminuta no se agranda hasta
     * verse pixelada.
     */
    public function test_la_foto_se_acomoda_a_cualquier_proporcion(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/productos', $this->datosProducto([
            // Una foto bien vertical, la que peor encaja en una caja cuadrada.
            'imagen' => UploadedFile::fake()->image('botella.jpg', 300, 900),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        // El listado se filtra: sin filtro el producto nuevo cae en otra página
        // y no se estaría comprobando nada.
        $rutas = [
            '/productos?buscar='.urlencode($producto->nombre),
            "/productos/{$producto->id}",
        ];

        foreach ($rutas as $ruta) {
            $html = $this->actingAs($this->admin())->get($ruta)->assertOk()->getContent();

            $this->assertStringContainsString('object-scale-down', $html, "Falta el ajuste en {$ruta}");
            $this->assertStringContainsString('max-h-full max-w-full', $html, "Falta el límite en {$ruta}");
            // Recortar la foto dejaría fuera parte del producto.
            $this->assertStringNotContainsString('object-cover', $html, "Se está recortando en {$ruta}");
        }
    }

    /** El mostrador arma sus tarjetas con Alpine y necesita el mismo ajuste. */
    public function test_el_mostrador_tambien_acomoda_la_foto(): void
    {
        // Sin turno abierto el mostrador no pinta la cuadrícula, sino el aviso
        // de abrir caja: no habría marcado que comprobar.
        Cajas::abrir(Caja::firstOrFail(), $this->admin(), 100);

        $html = $this->actingAs($this->admin())->get('/pos')->assertOk()->getContent();

        $this->assertStringContainsString('object-scale-down', $html);
        $this->assertStringNotContainsString('object-cover', $html);
    }

    public function test_un_producto_sin_foto_no_rompe_el_mostrador(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->getJson('/pos/productos?q=7750001000011')
            ->assertOk();

        $this->assertNull($respuesta->json('0.imagen'));
    }

    // --------------------------------------------------------- configuración

    public function test_la_configuracion_del_negocio_se_lee_de_la_base(): void
    {
        Config::olvidar();

        $this->assertSame(0.18, Config::tasaImpuesto());
        $this->assertSame('Bs', Config::moneda());
        $this->assertSame('Bs 4.50', Config::importe(4.5));
        $this->assertSame('120', Config::cantidad('120.000'));
        $this->assertSame('2.5', Config::cantidad('2.500'));
    }

    /**
     * Un comprobante congela el código de su moneda, así que uno viejo debe
     * seguir mostrando su símbolo aunque el negocio haya cambiado de moneda.
     */
    public function test_el_simbolo_sale_del_codigo_congelado_en_el_documento(): void
    {
        Config::olvidar();

        $this->assertSame('Bs', Config::simbolo('BOB'));
        $this->assertSame('S/', Config::simbolo('PEN'));
        $this->assertSame('$', Config::simbolo('USD'));

        // Un código desconocido se muestra tal cual: mejor eso que un símbolo
        // equivocado.
        $this->assertSame('CLP', Config::simbolo('CLP'));

        // Sin código, el del negocio.
        $this->assertSame('Bs', Config::simbolo(null));
    }
}
