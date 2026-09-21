<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Ventas;
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
            'codigo' => 'P-9001',
            'nombre' => 'Producto de prueba',
            'precio_venta' => '8.00',
            'afecto_impuesto' => 1,
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    // ------------------------------------------------------------- permisos

    public function test_el_cajero_no_entra_al_menu(): void
    {
        foreach (['/menu', '/categorias'] as $ruta) {
            $this->actingAs($this->cajero())->get($ruta)->assertForbidden();
        }
    }

    /** El menú lo mantiene el administrador; la cocina solo prepara lo que hay en él. */
    public function test_la_cocina_no_entra_al_menu(): void
    {
        $cocina = Usuario::where('usuario', 'cocina1')->firstOrFail();

        foreach (['/menu', '/menu/nuevo', '/categorias'] as $ruta) {
            $this->actingAs($cocina)->get($ruta)->assertForbidden();
        }

        foreach (['/menu', '/menu/nuevo', '/categorias'] as $ruta) {
            $this->actingAs($this->admin())->get($ruta)->assertOk();
        }
    }

    public function test_las_pantallas_del_producto_responden(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->admin())->get("/menu/{$producto->id}")->assertOk();
        $this->actingAs($this->admin())->get("/menu/{$producto->id}/editar")->assertOk();
    }

    // ------------------------------------------------------------- productos

    public function test_se_crea_un_producto(): void
    {
        $this->actingAs($this->admin())
            ->post('/menu', $this->datosProducto())
            ->assertRedirect();

        $this->assertDatabaseHas('productos', [
            'codigo' => 'P-9001',
            'nombre' => 'Producto de prueba',
            'precio_venta' => '8.00',
        ]);
    }

    public function test_no_se_repite_el_codigo_interno(): void
    {
        $this->actingAs($this->admin())
            ->post('/menu', $this->datosProducto(['codigo' => 'P-0001']))
            ->assertSessionHasErrors('codigo');
    }

    public function test_el_cambio_de_precio_queda_auditado(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->admin())->put("/menu/{$producto->id}", $this->datosProducto([
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'precio_venta' => '4.50',
        ]))->assertRedirect();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'CAMBIO_PRECIO',
            'entidad' => 'productos',
            'entidad_id' => $producto->id,
        ]);
    }

    public function test_un_producto_ya_vendido_se_descataloga_en_vez_de_borrarse(): void
    {
        $producto = $this->producto();

        Ventas::registrar(
            sesion: Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), 100),
            usuario: $this->admin(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        $this->actingAs($this->admin())->delete("/menu/{$producto->id}")->assertRedirect('/menu');

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'activo' => 0]);
    }

    /** La cifra de cabecera cuenta el catálogo vigente, no los descatalogados. */
    public function test_el_resumen_del_catalogo_cuenta_los_vigentes(): void
    {
        $respuesta = $this->actingAs($this->admin())->get('/menu')->assertOk();

        $this->assertSame(Producto::activos()->count(), $respuesta->viewData('resumen')['total']);

        $this->producto()->forceFill(['activo' => 0])->save();

        $this->assertSame(
            Producto::activos()->count(),
            $this->actingAs($this->admin())->get('/menu')->viewData('resumen')['total'],
        );
    }

    // ---------------------------------------------------------------- precios

    /** El precio de estante sale de la base más el impuesto vigente. */
    public function test_el_precio_de_estante_agrega_el_impuesto(): void
    {
        $producto = $this->producto(); // base 3.98, tasa 0.13

        $this->assertSame(4.50, $producto->precio_estante);
    }

    public function test_un_producto_exonerado_no_lleva_impuesto_en_el_estante(): void
    {
        $producto = $this->producto();
        $producto->afecto_impuesto = false;

        $this->assertSame(3.98, $producto->precio_estante);
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
        $categoria = Categoria::where('nombre', 'Entradas')->firstOrFail();

        $this->actingAs($this->admin())->delete("/categorias/{$categoria->id}")->assertRedirect('/categorias');

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'activo' => 0]);
    }

    // ------------------------------------------------------------ fotos

    public function test_se_guarda_la_foto_de_un_producto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
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

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
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

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('vieja.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $anterior = $producto->imagen;

        $this->actingAs($this->admin())->put("/menu/{$producto->id}", $this->datosProducto([
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

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $archivo = $producto->imagen;

        $this->actingAs($this->admin())->put("/menu/{$producto->id}", $this->datosProducto([
            'quitar_imagen' => 1,
        ]))->assertRedirect();

        $this->assertNull($producto->fresh()->imagen);
        Storage::disk('public')->assertMissing($archivo);
    }

    /** Editar sin tocar la foto la conserva. */
    public function test_editar_sin_subir_nada_conserva_la_foto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();
        $archivo = $producto->imagen;

        $this->actingAs($this->admin())
            ->put("/menu/{$producto->id}", $this->datosProducto(['nombre' => 'Otro nombre']))
            ->assertRedirect();

        $this->assertSame($archivo, $producto->fresh()->imagen);
        Storage::disk('public')->assertExists($archivo);
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            'imagen' => UploadedFile::fake()->create('lista.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('imagen');

        $this->assertDatabaseMissing('productos', ['codigo' => 'P-9001']);
    }

    public function test_una_imagen_demasiado_pesada_se_rechaza(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            'imagen' => UploadedFile::fake()->image('enorme.jpg')->size(3000),
        ]))->assertSessionHasErrors('imagen');
    }

    /** El mostrador necesita la URL de la foto para pintar sus tarjetas. */
    public function test_el_mostrador_recibe_la_foto_del_producto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
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

        $this->actingAs($this->admin())->post('/menu', $this->datosProducto([
            // Una foto bien vertical, la que peor encaja en una caja cuadrada.
            'imagen' => UploadedFile::fake()->image('botella.jpg', 300, 900),
        ]));

        $producto = Producto::where('codigo', 'P-9001')->firstOrFail();

        // El listado se filtra: sin filtro el producto nuevo cae en otra página
        // y no se estaría comprobando nada.
        $rutas = [
            '/menu?buscar='.urlencode($producto->nombre),
            "/menu/{$producto->id}",
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
        // El código se lee de la base y no se escribe acá. Esta prueba tenía
        // uno fijo; cuando los códigos sembrados cambiaron, la búsqueda empezó
        // a devolver una lista vacía, `json('0.imagen')` daba null y la prueba
        // seguía en verde sin haber mirado ningún producto.
        $producto = $this->producto();

        $respuesta = $this->actingAs($this->admin())
            ->getJson('/pos/productos?q='.$producto->codigo)
            ->assertOk();

        $this->assertSame($producto->id, $respuesta->json('0.id'),
            'la búsqueda no devolvió el producto: la prueba no estaría midiendo nada');
        $this->assertNull($respuesta->json('0.imagen'));
    }

    // --------------------------------------------------------- configuración

    public function test_la_configuracion_del_negocio_se_lee_de_la_base(): void
    {
        Config::olvidar();

        $this->assertSame(0.13, Config::tasaImpuesto());
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
