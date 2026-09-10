<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class PuntoDeVentaTest extends TestCase
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

    private function producto(string $codigo = 'P-0001'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function efectivo(): MetodoPago
    {
        return MetodoPago::where('codigo', 'EFECTIVO')->firstOrFail();
    }

    /** Un medio que NO pasa por el cajón: tarjeta, transferencia o billetera. */
    private function noEfectivo(): MetodoPago
    {
        return MetodoPago::where('codigo', '<>', 'EFECTIVO')->where('activo', 1)->firstOrFail();
    }

    /** Abre un turno para el usuario dado. */
    private function turno(?Usuario $usuario = null, float $inicial = 100): SesionCaja
    {
        $usuario ??= $this->cajero();

        return Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial);
    }

    /**
     * Registra una venta simple de $cantidad unidades de un producto.
     *
     * @param  array<string, mixed>  $extra
     */
    private function vender(SesionCaja $sesion, Producto $producto, float $cantidad = 2, array $extra = []): Venta
    {
        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [[
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) $producto->precio_venta,
            ]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
            cliente: $extra['cliente'] ?? null,
            descuento: $extra['descuento'] ?? 0,
        );
    }

    // -------------------------------------------------------------- permisos

    /**
     * Vender y consultar ventas son permisos distintos. El almacenero no tiene
     * `ventas.registrar`, así que no entra al mostrador; pero sí tiene
     * `reportes.ver`, y el listado de ventas es información de gestión.
     */
    public function test_el_almacenero_consulta_ventas_pero_no_vende(): void
    {
        $this->actingAs($this->almacenero())->get('/pos')->assertForbidden();
        $this->actingAs($this->almacenero())->get('/ventas')->assertOk();

        $this->actingAs($this->almacenero())
            ->post('/pos', [
                'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => 1, 'precio_unitario' => 3.81]],
                'pagos' => [['metodo_pago_id' => $this->efectivo()->id]],
            ])
            ->assertForbidden();
    }

    public function test_el_cajero_entra_al_mostrador_y_a_la_caja(): void
    {
        foreach (['/pos', '/caja', '/ventas', '/clientes', '/comprobantes'] as $ruta) {
            $this->actingAs($this->cajero())->get($ruta)->assertOk();
        }
    }

    /** Sin caja abierta no hay dónde imputar el dinero. */
    public function test_no_se_vende_sin_caja_abierta(): void
    {
        $this->actingAs($this->cajero())
            ->post('/pos', [
                'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => 1, 'precio_unitario' => '3.81']],
                'pagos' => [['metodo_pago_id' => $this->efectivo()->id]],
            ])
            ->assertRedirect('/caja');
    }

    // ------------------------------------------------------------------ caja

    public function test_se_abre_y_se_cierra_un_turno(): void
    {
        $sesion = $this->turno(inicial: 150);

        $this->assertSame('ABIERTA', $sesion->estado);
        $this->assertDatabaseHas('auditoria', ['accion' => 'CAJA_ABIERTA', 'entidad_id' => $sesion->id]);

        $cerrada = Cajas::cerrar($sesion, $this->cajero(), 150, 'Sin novedad');

        $this->assertSame('CERRADA', $cerrada->estado);
        $this->assertSame('150.00', $cerrada->monto_esperado);
        $this->assertSame('0.00', $cerrada->diferencia);
    }

    public function test_una_caja_no_admite_dos_turnos_abiertos(): void
    {
        $this->turno($this->cajero());

        $this->expectException(RuntimeException::class);

        Cajas::abrir(Caja::firstOrFail(), $this->admin(), 50);
    }

    /** El esperado sale del inicial más lo cobrado en efectivo, más o menos los movimientos. */
    public function test_el_arqueo_suma_ventas_y_movimientos(): void
    {
        $sesion = $this->turno(inicial: 100);
        $venta = $this->vender($sesion, $this->producto(), 2);

        Cajas::movimiento($sesion, $this->cajero(), 'EGRESO', 'Compra de bolsas', 12.50);
        Cajas::movimiento($sesion, $this->cajero(), 'INGRESO', 'Aporte del dueño', 20);

        $esperado = round(100 + (float) $venta->fresh()->total - 12.50 + 20, 2);

        $this->assertSame($esperado, $sesion->efectivoEsperado());

        $cerrada = Cajas::cerrar($sesion, $this->cajero(), $esperado);

        $this->assertSame(number_format($esperado, 2, '.', ''), $cerrada->monto_esperado);
        $this->assertSame('0.00', $cerrada->diferencia);
    }

    public function test_el_faltante_queda_registrado_como_diferencia(): void
    {
        $sesion = $this->turno(inicial: 100);

        $cerrada = Cajas::cerrar($sesion, $this->cajero(), 90, 'Faltó vuelto');

        $this->assertSame('-10.00', $cerrada->diferencia);
    }

    public function test_una_caja_cerrada_no_admite_mas_movimientos(): void
    {
        $sesion = $this->turno();
        Cajas::cerrar($sesion, $this->cajero(), 100);

        $this->expectException(RuntimeException::class);

        Cajas::movimiento($sesion->fresh(), $this->cajero(), 'INGRESO', 'Tarde', 10);
    }

    // ---------------------------------------------------------------- ventas

    public function test_una_venta_descuenta_stock_y_deja_kardex(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $venta = $this->vender($sesion, $producto, 3);

        $this->assertSame($antes - 3, (float) $producto->fresh()->stock_actual);

        // El trigger de la base escribe el movimiento: aquí se comprueba que ocurre.
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'venta_id' => $venta->id,
            'origen' => 'VENTA',
            'tipo' => 'SALIDA',
        ]);
    }

    /** El precio se guarda sin impuesto; el total lo lleva encima. */
    public function test_los_totales_salen_del_detalle(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto(); // base 3.81, afecto al 18%

        $venta = $this->vender($sesion, $producto, 2)->fresh();

        $this->assertSame('7.62', $venta->subtotal);   // 3.81 × 2
        $this->assertSame('1.37', $venta->impuesto);   // ROUND(7.62 × 0.18, 2)
        $this->assertSame('8.99', $venta->total);
    }

    public function test_el_descuento_reduce_el_impuesto_en_proporcion(): void
    {
        $sesion = $this->turno();

        $venta = $this->vender($sesion, $this->producto(), 2, ['descuento' => 1.62])->fresh();

        $this->assertSame('7.62', $venta->subtotal);
        $this->assertSame('1.62', $venta->descuento);
        // impuesto bruto 1.37 × (7.62 − 1.62) / 7.62
        $this->assertSame('1.08', $venta->impuesto);
        $this->assertSame('7.08', $venta->total);
    }

    public function test_no_se_vende_mas_de_lo_que_hay_en_stock(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay stock suficiente');

        $this->vender($sesion, $producto, (float) $producto->stock_actual + 1);
    }

    /** Si algo falla a media venta, no queda nada a medias (RNF5). */
    public function test_la_venta_es_atomica(): void
    {
        $sesion = $this->turno();
        $bueno = $this->producto('P-0001');
        $malo = $this->producto('P-0006');

        $ventasAntes = Venta::count();
        $stockAntes = (float) $bueno->stock_actual;

        try {
            Ventas::registrar(
                sesion: $sesion,
                usuario: $this->cajero(),
                lineas: [
                    ['producto_id' => $bueno->id, 'cantidad' => 1, 'precio_unitario' => 3.81],
                    ['producto_id' => $malo->id, 'cantidad' => (float) $malo->stock_actual + 50, 'precio_unitario' => 1.27],
                ],
                pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
            );
            $this->fail('La venta debió fallar por falta de stock.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame($ventasAntes, Venta::count());
        $this->assertSame($stockAntes, (float) $bueno->fresh()->stock_actual);
    }

    public function test_el_pago_sin_importe_toma_el_total(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto(), 2)->fresh(['pagos']);

        $this->assertSame($venta->total, $venta->pagos->first()->monto);
    }

    public function test_el_pago_que_no_cubre_el_total_se_rechaza(): void
    {
        $sesion = $this->turno();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no coincide con el total');

        Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2, 'precio_unitario' => 3.81]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => 5.00]],
        );
    }

    public function test_el_vuelto_sale_del_efectivo_recibido(): void
    {
        $sesion = $this->turno();

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2, 'precio_unitario' => 3.81]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null, 'monto_recibido' => 10.00]],
        )->fresh(['pagos']);

        $this->assertSame('8.99', $venta->total);
        $this->assertSame('1.01', $venta->pagos->first()->vuelto);
    }

    // ---------------------------------------------------------- comprobantes

    public function test_la_venta_al_paso_emite_recibo_a_nombre_generico(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        $comprobante = $venta->comprobante;

        $this->assertNotNull($comprobante);
        $this->assertSame('REC', $comprobante->serie->tipo->codigo);
        $this->assertSame('Cliente varios', $comprobante->cliente_nombre);
        $this->assertStringStartsWith('R001-', $comprobante->numero_completo);
    }

    public function test_la_persona_juridica_recibe_factura(): void
    {
        $sesion = $this->turno();
        $empresa = Cliente::where('tipo_persona', 'JURIDICA')->firstOrFail();

        $venta = $this->vender($sesion, $this->producto(), 2, ['cliente' => $empresa]);
        $comprobante = $venta->comprobante;

        $this->assertSame('FAC', $comprobante->serie->tipo->codigo);
        $this->assertStringStartsWith('F001-', $comprobante->numero_completo);
        $this->assertSame($empresa->razon_social, $comprobante->cliente_nombre);
        $this->assertSame($empresa->documento, $comprobante->cliente_documento);
        // La dirección fiscal se congela: si mañana la cambian, este documento no.
        $this->assertSame($empresa->direccion, $comprobante->cliente_direccion);
    }

    public function test_la_persona_natural_registrada_recibe_recibo(): void
    {
        $sesion = $this->turno();
        $persona = Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();

        $venta = $this->vender($sesion, $this->producto(), 1, ['cliente' => $persona]);

        $this->assertSame('REC', $venta->comprobante->serie->tipo->codigo);
        $this->assertSame($persona->nombre, $venta->comprobante->cliente_nombre);
    }

    /** El correlativo avanza de uno en uno y no se repite. */
    public function test_el_correlativo_avanza_sin_repetirse(): void
    {
        $sesion = $this->turno();

        $primero = $this->vender($sesion, $this->producto(), 1)->comprobante;
        $segundo = $this->vender($sesion, $this->producto(), 1)->comprobante;

        $this->assertSame($primero->numero + 1, $segundo->numero);
        $this->assertNotSame($primero->numero_completo, $segundo->numero_completo);
    }

    public function test_el_comprobante_congela_los_importes_de_la_venta(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto(), 2)->fresh();

        $this->assertSame($venta->subtotal, $venta->comprobante->subtotal);
        $this->assertSame($venta->impuesto, $venta->comprobante->impuesto);
        $this->assertSame($venta->total, $venta->comprobante->total);
    }

    public function test_el_comprobante_se_puede_imprimir(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        $this->actingAs($this->cajero())
            ->get("/comprobantes/{$venta->comprobante->id}/imprimir")
            ->assertOk()
            ->assertSee($venta->comprobante->numero_completo);

        $this->actingAs($this->cajero())
            ->get("/comprobantes/{$venta->comprobante->id}/imprimir?formato=a4")
            ->assertOk();
    }

    /**
     * El régimen tributario todavía no está definido. Mientras tanto el
     * documento lo dice, en vez de aparentar una validez que no tiene.
     */
    public function test_el_documento_avisa_que_lo_tributario_esta_en_construccion(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        $this->actingAs($this->cajero())
            ->get("/comprobantes/{$venta->comprobante->id}/imprimir")
            ->assertOk()
            ->assertSee('EN CONSTRUCCIÓN')
            ->assertSee('Sin validez tributaria');

        $this->actingAs($this->cajero())
            ->get('/comprobantes')
            ->assertOk()
            ->assertSee('Régimen tributario en construcción');
    }

    // -------------------------------------------------------------- anulación

    public function test_anular_devuelve_el_stock_y_anula_el_documento(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $venta = $this->vender($sesion, $producto, 3);
        $comprobante = $venta->comprobante;

        $this->assertSame($antes - 3, (float) $producto->fresh()->stock_actual);

        Ventas::anular($venta, $this->admin(), 'Error en el cobro');

        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);
        $this->assertSame('ANULADA', $venta->fresh()->estado);
        $this->assertSame('ANULADO', $comprobante->fresh()->estado);

        // El correlativo no se reutiliza: el documento anulado conserva su número.
        $this->assertDatabaseHas('comprobantes', [
            'id' => $comprobante->id,
            'numero_completo' => $comprobante->numero_completo,
        ]);

        $this->assertDatabaseHas('movimientos_inventario', [
            'venta_id' => $venta->id,
            'origen' => 'ANULACION',
            'tipo' => 'ENTRADA',
        ]);
    }

    public function test_una_venta_anulada_no_se_anula_dos_veces(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        Ventas::anular($venta, $this->admin(), 'Primera anulación');

        $this->expectException(RuntimeException::class);

        Ventas::anular($venta->fresh(), $this->admin(), 'Segunda');
    }

    public function test_el_cajero_no_puede_anular(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        $this->actingAs($this->cajero())
            ->post("/ventas/{$venta->id}/anular", ['motivo_anulacion' => 'Me equivoqué de producto'])
            ->assertForbidden();
    }

    public function test_la_anulacion_exige_motivo(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, $this->producto());

        $this->actingAs($this->admin())
            ->post("/ventas/{$venta->id}/anular", ['motivo_anulacion' => ''])
            ->assertSessionHasErrors('motivo_anulacion');
    }

    public function test_una_venta_anulada_no_suma_al_arqueo(): void
    {
        $sesion = $this->turno(inicial: 100);
        $venta = $this->vender($sesion, $this->producto(), 2);

        $conVenta = $sesion->efectivoEsperado();
        $this->assertGreaterThan(100, $conVenta);

        Ventas::anular($venta, $this->admin(), 'Anulada en el turno');

        $this->assertSame(100.0, $sesion->efectivoEsperado());
    }

    // ------------------------------------------------------------ mostrador

    // ------------------------------------------------- la unidad en el papel

    /**
     * Un «2.5» pelado no le dice nada a quien compró dos kilos y medio de
     * arroz, y comprobar lo que le cobraron es para lo que sirve el papel.
     */
    public function test_el_ticket_dice_la_unidad_de_lo_que_se_vendio(): void
    {
        $porKilo = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('codigo', 'KG'))
            ->firstOrFail();

        $venta = $this->vender($this->turno(), $porKilo, 2.5);
        $linea = $venta->detalle()->firstOrFail();

        $this->assertSame('KG', $linea->unidad);
        $this->assertSame('2.5 KG', $linea->cantidad_con_unidad);

        $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', $venta->comprobante))
            ->assertOk()
            ->assertSee('2.5 KG');
    }

    /**
     * En 80 mm no caben cuatro columnas: de los 50 px que le tocaban a cada
     * cifra, «1,234.00» ya pide 56, y cualquier venta de más de mil se montaba
     * encima de la columna de al lado. En el rollo la cantidad y el precio
     * bajan bajo la descripción; en A4, donde sobra sitio, siguen en columna.
     */
    public function test_el_rollo_no_lleva_las_columnas_que_no_le_caben(): void
    {
        $producto = Producto::activos()->firstOrFail();
        $venta = $this->vender($this->turno(), $producto, 2);
        $ruta = '/comprobantes/'.$venta->comprobante->id.'/imprimir';

        // Se busca la CELDA y no el texto suelto: «P. Unit» también aparece en
        // el comentario del CSS que explica por qué no está en el rollo, y una
        // prueba que se conforma con el texto daría por bueno cualquier cosa.
        $columna = '<th class="cifra derecha">P. Unit</th>';

        $this->actingAs($this->admin())->get($ruta.'?formato=ticket')
            ->assertOk()
            ->assertDontSee($columna, false)
            ->assertSee('<th class="cifra derecha">Importe</th>', false);

        $this->actingAs($this->admin())->get($ruta.'?formato=a4')
            ->assertOk()
            ->assertSee($columna, false);
    }

    /**
     * La unidad se copia, como el nombre y el precio. Si se leyera del catálogo
     * al imprimir, corregir un producto de unidades a kilos reescribiría todos
     * los tickets viejos, y un comprobante reimpreso dejaría de coincidir con
     * el que se entregó en mano.
     */
    public function test_cambiar_la_unidad_del_producto_no_reescribe_las_ventas_viejas(): void
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('codigo', 'UND'))
            ->firstOrFail();

        $venta = $this->vender($this->turno(), $producto, 3);

        $producto->forceFill([
            'unidad_medida_id' => UnidadMedida::where('codigo', 'KG')->firstOrFail()->id,
        ])->save();

        $this->assertSame('UND', $venta->detalle()->firstOrFail()->unidad);
    }

    public function test_la_busqueda_del_mostrador_encuentra_por_codigo_de_barras(): void
    {
        $this->turno();

        $conCodigo = Producto::activos()->whereNotNull('codigo_barras')->firstOrFail();

        $respuesta = $this->actingAs($this->cajero())
            ->getJson('/pos/productos?q='.$conCodigo->codigo_barras)
            ->assertOk();

        $this->assertSame($conCodigo->codigo, $respuesta->json('0.codigo'),
            'el código escaneado tiene que devolver SU producto, no uno parecido');
        $this->assertSame($conCodigo->precio_estante, $respuesta->json('0.precio_estante'));
    }

    /** La venta completa por HTTP, tal como la envía el mostrador. */
    public function test_el_mostrador_registra_la_venta_de_punta_a_punta(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $antes = (float) $producto->stock_actual;

        $respuesta = $this->actingAs($this->cajero())->post('/pos', [
            'lineas' => [[
                'producto_id' => $producto->id,
                'cantidad' => 2,
                'precio_unitario' => (float) $producto->precio_venta,
            ]],
            'pagos' => [[
                'metodo_pago_id' => $this->efectivo()->id,
                'monto_recibido' => 10.00,
            ]],
        ]);

        $venta = Venta::where('sesion_caja_id', $sesion->id)->latest('id')->firstOrFail();

        $respuesta->assertRedirect(route('ventas.show', $venta));

        $this->assertSame('8.99', $venta->total);
        $this->assertSame($antes - 2, (float) $producto->fresh()->stock_actual);
        $this->assertNotNull($venta->comprobante);
    }

    /**
     * El precio de línea siempre sale del catálogo del servidor, nunca del
     * request: si se confiara en `precio_unitario` del navegador, bastaría
     * mandarlo casi en cero para vender por debajo de precio sin pasar por
     * la autorización de descuento (O4).
     */
    public function test_el_precio_de_linea_no_se_puede_manipular_desde_el_navegador(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();

        $respuesta = $this->actingAs($this->cajero())->post('/pos', [
            'lineas' => [[
                'producto_id' => $producto->id,
                'cantidad' => 2,
                'precio_unitario' => 0.01,
            ]],
            'pagos' => [[
                'metodo_pago_id' => $this->efectivo()->id,
                'monto_recibido' => 10.00,
            ]],
        ]);

        $venta = Venta::where('sesion_caja_id', $sesion->id)->latest('id')->firstOrFail();

        $respuesta->assertRedirect(route('ventas.show', $venta));
        $this->assertSame('8.99', $venta->total);
        $this->assertSame('3.81', (string) $venta->detalle->first()->precio_unitario);
    }

    /** Un descuento por encima del umbral necesita el permiso `ventas.descuento`. */
    public function test_el_cajero_no_descuenta_por_encima_del_umbral(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();

        $this->actingAs($this->cajero())->post('/pos', [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio_unitario' => 3.81]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()->id]],
            'descuento' => 3.00, // ~39% sobre 7.62, muy por encima del 10%
        ])->assertSessionHas('error');

        $this->assertSame(0, Venta::where('sesion_caja_id', $sesion->id)->count());
    }

    public function test_el_cajero_si_descuenta_por_debajo_del_umbral(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->cajero())->post('/pos', [
            'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => 2, 'precio_unitario' => 3.81]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()->id]],
            'descuento' => 0.50, // 6.6%
        ])->assertSessionHasNoErrors();

        $venta = Venta::where('sesion_caja_id', $sesion->id)->firstOrFail();

        $this->assertSame('0.50', $venta->descuento);
    }

    // ---------------------------------------------------------------- clientes

    public function test_la_persona_juridica_exige_ruc_y_direccion(): void
    {
        $this->actingAs($this->admin())->post('/clientes', [
            'tipo_persona' => 'JURIDICA',
            'tipo_documento' => 'NIT',
            'razon_social' => 'Empresa Sin Datos S.A.C.',
        ])->assertSessionHasErrors(['documento', 'direccion']);
    }

    public function test_se_registra_una_persona_juridica(): void
    {
        $this->actingAs($this->admin())->post('/clientes', [
            'tipo_persona' => 'JURIDICA',
            'tipo_documento' => 'NIT',
            'documento' => '20999888777',
            'razon_social' => 'Comercial Nueva S.A.C.',
            'direccion' => 'Av. Nueva 100',
            'activo' => 1,
        ])->assertRedirect('/clientes');

        $cliente = Cliente::where('documento', '20999888777')->firstOrFail();

        $this->assertSame('Comercial Nueva S.A.C.', $cliente->nombre);
        $this->assertNull($cliente->nombres);
    }

    public function test_la_persona_natural_exige_nombres_y_apellidos(): void
    {
        $this->actingAs($this->admin())->post('/clientes', [
            'tipo_persona' => 'NATURAL',
            'tipo_documento' => 'CI',
            'documento' => '11223344',
        ])->assertSessionHasErrors(['nombres', 'apellidos']);
    }

    public function test_un_cliente_con_compras_se_desactiva_en_vez_de_borrarse(): void
    {
        $sesion = $this->turno();
        $cliente = Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();

        $this->vender($sesion, $this->producto(), 1, ['cliente' => $cliente]);

        $this->actingAs($this->admin())->delete("/clientes/{$cliente->id}")->assertRedirect('/clientes');

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'activo' => 0]);
    }

    // ------------------------------------------------- pago partido en varios

    /**
     * El cliente paga una parte en efectivo y otra por otro medio.
     *
     * El dominio ya lo admitía; lo que faltaba era el mostrador. Estas pruebas
     * fijan el comportamiento para que un cambio en el punto de venta no se lo
     * lleve por delante.
     */
    public function test_una_venta_admite_varias_formas_de_pago(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 2]],
            pagos: [
                ['metodo_pago_id' => $this->efectivo()->id, 'monto' => 5.00, 'monto_recibido' => 10.00],
                ['metodo_pago_id' => $this->noEfectivo()->id, 'monto' => null],
            ],
        );

        $pagos = $venta->pagos()->get();
        $this->assertCount(2, $pagos);

        // La suma de las formas de pago es exactamente el total.
        $this->assertEqualsWithDelta(
            (float) $venta->total,
            $pagos->sum(fn ($p) => (float) $p->monto),
            0.01,
        );

        // El vuelto sale de SU línea, no del total de la venta.
        $efectivo = $pagos->firstWhere('metodo_pago_id', $this->efectivo()->id);
        $this->assertEqualsWithDelta(5.00, (float) $efectivo->vuelto, 0.01);
    }

    /** La línea sin importe recibe lo que falte, no el total. */
    public function test_la_forma_de_pago_sin_importe_toma_el_resto(): void
    {
        $sesion = $this->turno();
        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2]],
            pagos: [
                ['metodo_pago_id' => $this->efectivo()->id, 'monto' => 3.00],
                ['metodo_pago_id' => $this->noEfectivo()->id, 'monto' => null],
            ],
        );

        $resto = $venta->pagos()->where('metodo_pago_id', $this->noEfectivo()->id)->value('monto');
        $this->assertEqualsWithDelta((float) $venta->total - 3.00, (float) $resto, 0.01);
    }

    /** Dos líneas en blanco no se pueden repartir: no se sabe cuál cubre qué. */
    public function test_dos_formas_de_pago_sin_importe_se_rechazan(): void
    {
        $sesion = $this->turno();

        $this->expectExceptionMessage('Solo una forma de pago puede quedar sin importe.');

        Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2]],
            pagos: [
                ['metodo_pago_id' => $this->efectivo()->id, 'monto' => null],
                ['metodo_pago_id' => $this->noEfectivo()->id, 'monto' => null],
            ],
        );
    }

    /**
     * De un pago partido, al cajón entra SOLO la parte en efectivo. Es la razón
     * de ser de `metodos_pago.afecta_caja`, y si esto se rompe el arqueo acusa
     * de faltante a un cajero que no debe nada.
     */
    public function test_al_arqueo_solo_entra_la_parte_en_efectivo(): void
    {
        $sesion = $this->turno(inicial: 100);

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 2]],
            pagos: [
                ['metodo_pago_id' => $this->efectivo()->id, 'monto' => 4.00],
                ['metodo_pago_id' => $this->noEfectivo()->id, 'monto' => null],
            ],
        );

        $this->assertGreaterThan(4.00, (float) $venta->total, 'la venta debe superar la parte en efectivo');

        $cerrada = Cajas::cerrar($sesion->fresh(), $this->admin(), 104.00);

        // 100 de apertura + 4 en efectivo: lo pagado por el otro medio no entra.
        $this->assertEqualsWithDelta(104.00, (float) $cerrada->monto_esperado, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $cerrada->diferencia, 0.01);
    }
}
