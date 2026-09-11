<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Comprar por caja y vender por unidad.
 *
 * El negocio recibe cajas de 24 y despacha gaseosas de a una. Antes había que
 * elegir: o el stock contaba cajas —y el mostrador no podía vender sueltas— o
 * contaba unidades y quien recibía la mercadería hacía la multiplicación de
 * cabeza en cada entrada. El caso que rompía las dos salidas es el de la caja
 * incompleta: llegan 3 cajas y 5 sueltas.
 *
 * La regla que estas pruebas defienden es una sola: el stock se cuenta SIEMPRE
 * en la unidad de venta, y el empaque es nada más la equivalencia con la que
 * se escribe la entrada. Si esa regla se rompiera, el mostrador vendería una
 * cosa y el almacén contaría otra.
 */
class EmpaqueDelProductoTest extends TestCase
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

    /**
     * Un producto que se vende por unidad y llega en cajas de 24.
     *
     * El empaque se pone a mano y no se toma del catálogo de ejemplo: así la
     * prueba sigue diciendo lo mismo el día que cambien los datos de la demo.
     */
    private function productoEnCajas(int $contenido = 24): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();

        $producto->forceFill([
            'contenido_empaque' => $contenido,
            'nombre_empaque' => 'Caja',
        ])->save();

        return $producto->fresh();
    }

    /** Arroz: se vende por kilo y llega en sacos. */
    private function productoEnSacos(float $contenido = 46): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 1))
            ->firstOrFail();

        $producto->forceFill([
            'contenido_empaque' => $contenido,
            'nombre_empaque' => 'Saco',
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

    /** @return array<string, mixed> */
    private function datosProducto(array $sobrescribir = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9101',
            'nombre' => 'Gaseosa de prueba',
            'precio_compra' => '4.00',
            'precio_venta' => '6.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '10',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    // -------------------------------------------------------- alta y edición

    public function test_se_da_de_alta_un_producto_que_viene_en_cajas(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '24',
                'stock_inicial' => '0',
            ]))
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9101')->firstOrFail();

        $this->assertSame(24.0, $producto->contenido_empaque);
        $this->assertSame('Caja', $producto->nombre_empaque);
        $this->assertTrue($producto->tieneEmpaque());
    }

    /**
     * El caso que trajo el cliente: llegaron 3 cajas y 5 sueltas porque la
     * cuarta vino a medias. El stock tiene que quedar en unidades.
     */
    public function test_el_stock_inicial_se_puede_cargar_en_cajas_y_sueltas(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '24',
                'empaques' => '3',
                'sueltas' => '5',
            ]))
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9101')->firstOrFail();

        $this->assertSame('77.000', $producto->stock_actual);

        // Y el kardex tiene que poder contrastarse con la factura del
        // proveedor, que está expresada en cajas y no en unidades.
        $movimiento = $producto->movimientos()->firstOrFail();

        $this->assertSame('INICIAL', $movimiento->origen);
        $this->assertSame('77.000', $movimiento->cantidad);
        $this->assertStringContainsString('3 cajas de 24', $movimiento->motivo);
        $this->assertStringContainsString('5 sueltas', $movimiento->motivo);
    }

    public function test_marcar_el_empaque_sin_decir_cuanto_trae_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
            ]))
            ->assertSessionHasErrors('contenido_empaque');

        $this->assertDatabaseMissing('productos', ['codigo' => 'P-9101']);
    }

    /** Un empaque de una sola unidad no ahorra ninguna cuenta. */
    public function test_un_empaque_de_una_unidad_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '1',
            ]))
            ->assertSessionHasErrors('contenido_empaque');
    }

    /**
     * Los dos campos del empaque no desaparecen de la página al desmarcar la
     * casilla: solo se ocultan, y el navegador los envía igual con lo que se
     * hubiera escrito antes de cambiar de idea. La casilla es la que manda.
     */
    public function test_un_producto_sin_empaque_se_guarda_aunque_lleguen_restos(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '0',
                'nombre_empaque' => 'Caja',
                // Un contenido que por sí solo no pasaría la validación.
                'contenido_empaque' => '1',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9101')->firstOrFail();

        $this->assertNull($producto->contenido_empaque);
        $this->assertNull($producto->nombre_empaque);
    }

    public function test_desmarcar_el_empaque_al_editar_lo_borra(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->admin())
            ->put("/productos/{$producto->id}", $this->datosProducto([
                'codigo' => $producto->codigo,
                'nombre' => $producto->nombre,
                'unidad_medida_id' => $producto->unidad_medida_id,
                'viene_en_empaque' => '0',
                // Aunque el navegador los mande igual, manda la casilla.
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '24',
            ]))
            ->assertRedirect();

        $producto = $producto->fresh();

        $this->assertNull($producto->contenido_empaque);
        $this->assertNull($producto->nombre_empaque);
        $this->assertFalse($producto->tieneEmpaque());
    }

    /**
     * Quien da de alta el producto tiene delante la factura del proveedor, y
     * ahí el costo está por caja. Lo que se guarda sigue siendo por unidad:
     * es lo que leen el margen, el valor del inventario y los reportes.
     */
    public function test_el_costo_del_alta_se_puede_escribir_por_caja(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '24',
                'precio_compra' => '96.00',
                'precio_compra_por' => 'EMPAQUE',
            ]))
            ->assertRedirect();

        $this->assertSame('4.00', Producto::where('codigo', 'P-9101')->firstOrFail()->precio_compra);
    }

    public function test_el_costo_del_alta_por_unidad_no_se_divide(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Caja',
                'contenido_empaque' => '24',
                'precio_compra' => '4.00',
                'precio_compra_por' => 'UNIDAD',
            ]))
            ->assertRedirect();

        $this->assertSame('4.00', Producto::where('codigo', 'P-9101')->firstOrFail()->precio_compra);
    }

    /** Sin empaque no hay entre qué dividir: el número se guarda como vino. */
    public function test_un_producto_a_granel_ignora_el_costo_por_caja(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'viene_en_empaque' => '0',
                'precio_compra' => '96.00',
                'precio_compra_por' => 'EMPAQUE',
            ]))
            ->assertRedirect();

        $this->assertSame('96.00', Producto::where('codigo', 'P-9101')->firstOrFail()->precio_compra);
    }

    // ------------------------------------------------- ingreso de mercadería

    public function test_el_almacen_ingresa_cajas_y_sueltas(): void
    {
        $producto = $this->productoEnCajas();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 3,
                'sueltas' => 5,
            ])
            ->assertRedirect();

        $this->assertSame($antes + 77, (float) $producto->fresh()->stock_actual);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('77.000', $movimiento->cantidad);
        $this->assertSame('3 cajas de 24 + 5 sueltas', $movimiento->motivo);
    }

    public function test_la_ficha_del_producto_ingresa_igual_que_el_almacen(): void
    {
        $producto = $this->productoEnCajas(12);
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('productos.ingreso', $producto), ['empaques' => 2, 'sueltas' => 0])
            ->assertRedirect();

        $this->assertSame($antes + 24, (float) $producto->fresh()->stock_actual);
    }

    /** La observación de quien recibe no se pierde: va detrás del desglose. */
    public function test_la_observacion_convive_con_el_desglose(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'motivo' => 'Una caja vino golpeada',
            ]);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('1 caja de 24 · Una caja vino golpeada', $movimiento->motivo);
    }

    /**
     * Quien recibe tiene delante la factura del proveedor, y ahí el precio
     * está por caja. El sistema divide; lo que se guarda sigue siendo el costo
     * por unidad, que es como lo lee el resto del sistema.
     */
    public function test_el_costo_escrito_por_caja_se_guarda_por_unidad(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'costo_unitario' => '96.00',
                'costo_por' => 'EMPAQUE',
            ]);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('4.00', $movimiento->costo_unitario);
    }

    public function test_el_costo_escrito_por_unidad_no_se_divide(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'costo_unitario' => '4.50',
                'costo_por' => 'UNIDAD',
            ]);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('4.50', $movimiento->costo_unitario);
    }

    /**
     * La forma de siempre sigue viva: un producto con empaque también acepta
     * que se escriba el total en unidades, sin pasar por las cajas.
     */
    public function test_un_producto_con_empaque_sigue_aceptando_la_cantidad_suelta(): void
    {
        $producto = $this->productoEnCajas();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), ['producto_id' => $producto->id, 'cantidad' => 30])
            ->assertRedirect();

        $this->assertSame($antes + 30, (float) $producto->fresh()->stock_actual);
    }

    /** Sin empaque no hay casillas de cajas, y lo que llegue por ahí se ignora. */
    public function test_un_producto_a_granel_ignora_las_cajas(): void
    {
        $producto = $this->productoAGranel();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'cantidad' => 2.5,
                'empaques' => 99,
            ])
            ->assertRedirect();

        $this->assertSame($antes + 2.5, (float) $producto->fresh()->stock_actual);
    }

    public function test_cero_cajas_y_cero_sueltas_no_es_un_ingreso(): void
    {
        $producto = $this->productoEnCajas();
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 0,
                'sueltas' => 0,
            ])
            ->assertSessionHasErrors('empaques');

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
    }

    /** La regla de decimales alcanza también a las sueltas. */
    public function test_media_unidad_suelta_se_rechaza_en_una_unidad_entera(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'sueltas' => 2.5,
            ])
            ->assertSessionHasErrors('sueltas');
    }

    /**
     * El proveedor sube la caja de 96 a 108. Si el costo se quedara solo en el
     * kardex, el sistema seguiría diciendo que se gana Bs 2.00 por unidad
     * cuando se ganan 1.50, y el valor del inventario quedaría corto.
     */
    public function test_el_ingreso_puede_actualizar_el_costo_del_producto(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '4.00'])->save();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'costo_unitario' => '108.00',
                'costo_por' => 'EMPAQUE',
                'actualizar_costo' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('4.50', $producto->fresh()->precio_compra);
    }

    /** Cambiar el costo mueve el margen de todos los reportes: queda auditado. */
    public function test_el_cambio_de_costo_queda_en_la_bitacora(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '4.00'])->save();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'costo_unitario' => '5.00',
                'actualizar_costo' => '1',
            ]);

        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $this->almacenero()->id,
            'accion' => 'CAMBIO_COSTO',
            'entidad' => 'productos',
            'entidad_id' => $producto->id,
        ]);
    }

    /**
     * Una compra puntual más cara —una urgencia, un flete— no tiene por qué
     * volverse el costo de referencia. Lo decide la casilla, no el sistema.
     */
    public function test_sin_marcar_la_casilla_el_costo_del_producto_no_se_toca(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '4.00'])->save();

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 1,
                'costo_unitario' => '9.00',
                'actualizar_costo' => '0',
            ])
            ->assertRedirect();

        $this->assertSame('4.00', $producto->fresh()->precio_compra);

        // Pero el kardex sí guarda lo que costó ESA entrada.
        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('9.00', $movimiento->costo_unitario);
    }

    /** La ficha del producto ofrece lo mismo que el almacén. */
    public function test_la_ficha_tambien_actualiza_el_costo(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '4.00'])->save();

        $this->actingAs($this->almacenero())
            ->post(route('productos.ingreso', $producto), [
                'empaques' => 1,
                'costo_unitario' => '4.75',
                'actualizar_costo' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('4.75', $producto->fresh()->precio_compra);
    }

    // -------------------------------------------------- lo que se pesa y mide

    /**
     * El empaque no es solo para lo que se cuenta. Un saco de 46 kg es el mismo
     * caso que una caja de 24 gaseosas, y la mitad de un saco es lo que en una
     * caja serían las sueltas.
     */
    public function test_se_ingresa_por_sacos_un_producto_que_se_vende_por_kilo(): void
    {
        $producto = $this->productoEnSacos(46);
        $antes = (float) $producto->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('inventario.ingreso'), [
                'producto_id' => $producto->id,
                'empaques' => 3,
                'sueltas' => 2.5,
            ])
            ->assertRedirect();

        $this->assertSame($antes + 140.5, (float) $producto->fresh()->stock_actual);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('3 sacos de 46 + 2.5 sueltas', $movimiento->motivo);
    }

    /**
     * Un galón son 3.785 litros. Con el contenido en entero había que redondear
     * a 4, y ese redondeo se iba derecho al stock: 10 galones cargados como 40
     * litros contra los 37.85 que entraron de verdad.
     */
    public function test_el_contenido_del_empaque_admite_fracciones(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', $this->datosProducto([
                'unidad_medida_id' => UnidadMedida::where('codigo', 'LT')->firstOrFail()->id,
                'viene_en_empaque' => '1',
                'nombre_empaque' => 'Galón',
                'contenido_empaque' => '3.785',
                'empaques' => '10',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $producto = Producto::where('codigo', 'P-9101')->firstOrFail();

        $this->assertSame(3.785, $producto->contenido_empaque);
        $this->assertSame('37.850', $producto->stock_actual);
    }

    /** Sigue sin admitirse un empaque que no ahorra ninguna cuenta. */
    public function test_un_empaque_de_una_unidad_o_menos_se_rechaza(): void
    {
        foreach (['1', '0.5'] as $contenido) {
            $this->actingAs($this->admin())
                ->post('/productos', $this->datosProducto([
                    'viene_en_empaque' => '1',
                    'nombre_empaque' => 'Caja',
                    'contenido_empaque' => $contenido,
                ]))
                ->assertSessionHasErrors('contenido_empaque');
        }
    }

    /** El desglose cuenta sacos enteros y deja el resto en kilos. */
    public function test_el_stock_en_kilos_se_lee_en_sacos(): void
    {
        $producto = $this->productoEnSacos(46);

        $this->assertSame('3 sacos y 2.5 sueltas', $producto->desglosar(140.5));
        $this->assertSame('2 sacos', $producto->desglosar(92));
        $this->assertSame('12.75 sueltas', $producto->desglosar(12.75));
    }

    // ---------------------------------------------------------- cómo se lee

    public function test_el_stock_se_lee_en_cajas_y_sueltas(): void
    {
        $producto = $this->productoEnCajas();

        $this->assertSame('3 cajas y 5 sueltas', $producto->desglosar(77));
        $this->assertSame('3 cajas', $producto->desglosar(72));
        $this->assertSame('1 caja y 1 suelta', $producto->desglosar(25));

        // Por debajo de una caja se dicen solo las sueltas: «0 cajas y 5
        // sueltas» es la misma información con una cifra de más.
        $this->assertSame('5 sueltas', $producto->desglosar(5));

        // De un agotado no hay nada que desglosar.
        $this->assertNull($producto->desglosar(0));
    }

    /**
     * Las cajas no se guardan: se calculan del stock. Por eso bajan solas
     * cuando el mostrador despacha, sin que nadie toque nada.
     */
    public function test_las_cajas_bajan_solas_cuando_se_vende(): void
    {
        $producto = $this->productoEnCajas();

        $this->assertSame('4 cajas', $producto->desglosar(96));
        $this->assertSame('3 cajas y 23 sueltas', $producto->desglosar(95));
        $this->assertSame('3 cajas', $producto->desglosar(72));
    }

    public function test_un_producto_a_granel_no_tiene_desglose(): void
    {
        $this->assertNull($this->productoAGranel()->desglosar(77));
    }

    public function test_la_etiqueta_del_empaque_dice_de_que_se_habla(): void
    {
        $producto = $this->productoEnCajas();

        $this->assertSame(
            'Caja de 24 '.$producto->unidadMedida->codigo,
            $producto->etiqueta_empaque,
        );
    }

    /** El plural del empaque es texto libre, y no basta con pegarle una «s». */
    public function test_el_nombre_del_empaque_se_pluraliza_bien(): void
    {
        $producto = $this->productoEnCajas();

        foreach ([
            'Caja' => 'cajas',
            'Paquete' => 'paquetes',
            'Cartón' => 'cartones',
            'Fardo' => 'fardos',
            'Pack' => 'packs',
        ] as $singular => $plural) {
            $producto->nombre_empaque = $singular;

            $this->assertSame($plural, $producto->empaque_plural, "plural de «{$singular}»");
        }
    }
}
