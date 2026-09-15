<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Comprobantes;
use App\Services\Devoluciones;
use App\Services\ReglasEnPhp;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class SustitucionComprobanteTest extends TestCase
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

    private function empresa(): Cliente
    {
        return Cliente::where('tipo_persona', 'JURIDICA')->firstOrFail();
    }

    private function persona(): Cliente
    {
        return Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), 200);
    }

    /** Venta al paso: sale con recibo a nombre genérico. */
    private function ventaConRecibo(SesionCaja $sesion, ?Cliente $cliente = null): Venta
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 2, 'precio_unitario' => 3.39]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    // -------------------------------------------------------------- permisos

    public function test_el_cajero_no_sustituye_comprobantes(): void
    {
        $sesion = $this->turno($this->cajero());
        $venta = $this->ventaConRecibo($sesion);

        $this->actingAs($this->cajero())
            ->post("/comprobantes/{$venta->comprobante->id}/sustituir", [
                'cliente_id' => $this->empresa()->id,
                'motivo' => 'El cliente pidió factura',
            ])
            ->assertForbidden();
    }

    // ----------------------------------------------------- el caso principal

    /** Recibo → factura: es para lo que existe la sustitución (HU-42). */
    public function test_un_recibo_se_sustituye_por_una_factura(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        $this->assertSame('REC', $recibo->serie->tipo->codigo);
        $this->assertNull($venta->cliente_id);

        $factura = Comprobantes::sustituir(
            $recibo, $this->admin(), $this->empresa(),
            'El cliente solicitó factura a nombre de su empresa',
        );

        // El nuevo documento es una factura, con su propio correlativo.
        $this->assertSame('FAC', $factura->serie->tipo->codigo);
        $this->assertStringStartsWith('F001-', $factura->numero_completo);
        $this->assertSame('EMITIDO', $factura->estado);

        // Congela los datos fiscales de la empresa.
        $this->assertSame($this->empresa()->razon_social, $factura->cliente_nombre);
        $this->assertSame($this->empresa()->documento, $factura->cliente_documento);
        $this->assertSame($this->empresa()->direccion, $factura->cliente_direccion);

        // El anterior no se borra: queda sustituido y conserva su número.
        $recibo->refresh();
        $this->assertSame('SUSTITUIDO', $recibo->estado);
        $this->assertNotNull($recibo->sustituido_en);

        // La cadena queda enlazada y con su motivo.
        $this->assertSame($recibo->id, (int) $factura->sustituye_a);
        $this->assertSame('El cliente solicitó factura a nombre de su empresa', $factura->motivo_emision);

        // La venta queda asociada a la empresa.
        $this->assertSame($this->empresa()->id, $venta->fresh()->cliente_id);
    }

    /** La venta no se toca: ni importes, ni stock, ni estado. */
    public function test_la_sustitucion_no_altera_la_venta_ni_el_stock(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $totalAntes = $venta->fresh()->total;
        $stockAntes = (float) $producto->fresh()->stock_actual;
        $movimientosAntes = $producto->movimientos()->count();

        Comprobantes::sustituir($venta->comprobante, $this->admin(), $this->empresa(), 'Pidió factura');

        $venta->refresh();

        $this->assertSame($totalAntes, $venta->total);
        $this->assertSame('COMPLETADA', $venta->estado);
        $this->assertSame($stockAntes, (float) $producto->fresh()->stock_actual);
        $this->assertSame($movimientosAntes, $producto->movimientos()->count());
    }

    /** El documento vigente es siempre uno solo. */
    public function test_solo_queda_un_documento_vigente(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);

        Comprobantes::sustituir($venta->comprobante, $this->admin(), $this->empresa(), 'Pidió factura');

        $this->assertSame(2, $venta->comprobantes()->count());
        $this->assertSame(1, $venta->comprobantes()->where('estado', 'EMITIDO')->count());
    }

    /** Se puede corregir dos veces: la cadena encadena. */
    public function test_la_cadena_de_sustituciones_se_encadena(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        $primera = Comprobantes::sustituir($recibo, $this->admin(), $this->empresa(), 'Pidió factura');

        $otraEmpresa = Cliente::where('tipo_persona', 'JURIDICA')
            ->where('id', '<>', $this->empresa()->id)
            ->firstOrFail();

        $segunda = Comprobantes::sustituir($primera, $this->admin(), $otraEmpresa, 'La factura iba a otra empresa');

        $this->assertSame($recibo->id, (int) $primera->fresh()->sustituye_a);
        $this->assertSame($primera->id, (int) $segunda->sustituye_a);
        $this->assertSame('SUSTITUIDO', $primera->fresh()->estado);
        $this->assertSame('EMITIDO', $segunda->estado);
        $this->assertSame(3, $venta->comprobantes()->count());
    }

    /** También al revés: una factura mal emitida vuelve a recibo. */
    public function test_una_factura_puede_volver_a_recibo(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion, $this->empresa());
        $factura = $venta->comprobante;

        $this->assertSame('FAC', $factura->serie->tipo->codigo);

        $recibo = Comprobantes::sustituir($factura, $this->admin(), null, 'Se facturó por error a esa empresa');

        $this->assertSame('REC', $recibo->serie->tipo->codigo);
        $this->assertSame('Cliente varios', $recibo->cliente_nombre);
        // La venta deja de estar asociada a la empresa.
        $this->assertNull($venta->fresh()->cliente_id);
    }

    // ------------------------------------------------------------- límites

    public function test_no_se_sustituye_un_documento_ya_sustituido(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        Comprobantes::sustituir($recibo, $this->admin(), $this->empresa(), 'Pidió factura');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya fue sustituido');

        Comprobantes::sustituir($recibo->fresh(), $this->admin(), $this->persona(), 'Otra vez');
    }

    public function test_no_se_sustituye_el_documento_de_una_venta_anulada(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        Ventas::anular($venta, $this->admin(), 'Error de cobro');

        // Anular la venta deja anulado también su documento, así que el
        // bloqueo se explica por el documento, que es lo que se iba a tocar.
        $this->assertSame('ANULADO', $recibo->fresh()->estado);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('anulado');

        Comprobantes::sustituir($recibo->fresh(), $this->admin(), $this->empresa(), 'Pidió factura');
    }

    public function test_no_se_sustituye_el_documento_de_una_venta_devuelta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Una unidad rota');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('devoluciones');

        Comprobantes::sustituir($recibo->fresh(), $this->admin(), $this->empresa(), 'Pidió factura');
    }

    /** El plazo lo fija `configuracion.dias_max_sustitucion`. */
    public function test_no_se_sustituye_pasado_el_plazo(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        $plazo = Comprobantes::plazoDias();
        Venta::whereKey($venta->id)->update(['fecha' => now()->subDays($plazo + 2)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plazo');

        Comprobantes::sustituir($recibo->fresh()->load('venta'), $this->admin(), $this->empresa(), 'Tarde');
    }

    /** Emitir el mismo documento otra vez solo gastaría un correlativo. */
    public function test_no_se_sustituye_por_uno_identico(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idéntico');

        Comprobantes::sustituir($venta->comprobante, $this->admin(), null, 'Sin cambios');
    }

    /** Una persona natural no puede recibir factura: lo corta el trigger. */
    public function test_una_persona_natural_no_recibe_factura(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion, $this->empresa());
        $factura = $venta->comprobante;

        // Pasarla a una persona natural emite recibo, no factura.
        $nuevo = Comprobantes::sustituir($factura, $this->admin(), $this->persona(), 'Era una persona, no la empresa');

        $this->assertSame('REC', $nuevo->serie->tipo->codigo);
        $this->assertSame($this->persona()->nombre, $nuevo->cliente_nombre);
    }

    // ------------------------------------------------------------------ HTTP

    public function test_la_sustitucion_funciona_de_punta_a_punta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        $recibo = $venta->comprobante;

        $this->actingAs($this->admin())
            ->post("/comprobantes/{$recibo->id}/sustituir", [
                'cliente_id' => $this->empresa()->id,
                'motivo' => 'El cliente solicitó factura',
            ])
            ->assertRedirect(route('ventas.show', $venta));

        $factura = Comprobante::where('venta_id', $venta->id)->where('estado', 'EMITIDO')->firstOrFail();

        $this->assertSame('FAC', $factura->serie->tipo->codigo);
        $this->assertSame($recibo->id, (int) $factura->sustituye_a);

        // La ficha de la venta muestra la cadena.
        $this->actingAs($this->admin())
            ->get("/ventas/{$venta->id}")
            ->assertOk()
            ->assertSee($factura->numero_completo)
            ->assertSee($recibo->numero_completo);
    }

    public function test_la_sustitucion_exige_motivo(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);

        $this->actingAs($this->admin())
            ->post("/comprobantes/{$venta->comprobante->id}/sustituir", [
                'cliente_id' => $this->empresa()->id,
                'motivo' => '',
            ])
            ->assertSessionHasErrors('motivo');
    }

    public function test_queda_auditada(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);

        $factura = Comprobantes::sustituir($venta->comprobante, $this->admin(), $this->empresa(), 'Pidió factura');

        // El procedimiento escribe su propia entrada.
        $this->assertDatabaseHas('auditoria', [
            'accion' => 'SUSTITUIR_COMPROBANTE',
            'entidad' => 'comprobantes',
            'entidad_id' => $factura->id,
            'usuario_id' => $this->admin()->id,
        ]);
    }

    /** El botón solo aparece cuando la sustitución es posible. */
    public function test_la_ficha_solo_ofrece_sustituir_cuando_se_puede(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);

        $this->actingAs($this->admin())
            ->get("/ventas/{$venta->id}")
            ->assertOk()
            ->assertSee('Sustituir comprobante');

        Ventas::anular($venta, $this->admin(), 'Error de cobro');

        $this->actingAs($this->admin())
            ->get("/ventas/{$venta->id}")
            ->assertOk()
            ->assertDontSee('Sustituir comprobante');
    }

    /**
     * El ticket se manda a imprimir solo cuando se lo piden.
     *
     * El botón de la venta recién cobrada abre la hoja con `?imprimir=1` y el
     * diálogo de impresión aparece sin un segundo clic. Abrir el mismo
     * documento desde el listado NO lo hace: ahí la intención es mirarlo, y
     * saltar el diálogo de la impresora encima sería molesto.
     */
    public function test_el_comprobante_solo_se_autoimprime_cuando_se_lo_pide(): void
    {
        $comprobante = $this->ventaConRecibo($this->turno())->comprobante;

        // Se busca el DISPARO automático, no «window.print()» a secas: el
        // botón manual de la barra también lo lleva, así que buscar eso daría
        // por buenas las dos versiones sin distinguirlas.
        // Tampoco sirve ya «addEventListener('load'»: el guion que mide el
        // alto del papel está en las DOS versiones y también escucha ese
        // evento. Queda la línea que solo existe en el disparo automático.
        $marca = 'const demora = performance.now() - antes;';

        $mirar = $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', $comprobante));
        $mirar->assertOk();
        $mirar->assertDontSee($marca, false);
        $mirar->assertSee('onclick="window.print()"', false);   // el botón sí está

        $imprimir = $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', [$comprobante, 'imprimir' => 1]));
        $imprimir->assertOk();
        $imprimir->assertSee($marca, false);

        // Y ni aun así se imprime en una pestaña común: el disparo está
        // condicionado a la ventana de la caja, la que abre el acceso directo
        // con `--app=`, que Chrome declara «standalone» (una pestaña normal se
        // declara «browser»). Esto mira el texto, no el comportamiento —acá no
        // corre JavaScript—, pero deja constancia de que la condición está:
        // sin ella, mostrarle el sistema a un cliente le planta el diálogo de
        // impresión encima.
        $imprimir->assertSee("matchMedia('(display-mode: standalone)')", false);
    }

    /**
     * El ticket sale a 80 mm y la otra vista, en A4.
     *
     * Esta prueba antes exigía `size: 80mm auto`, que es lo que decía la hoja
     * y lo que se lee en medio internet. Y pasaba en verde mientras el ticket
     * salía en A4: mezclar una medida con la palabra `auto` es CSS inválido,
     * el navegador descarta la regla entera sin avisar y usa el papel por
     * defecto de la impresora.
     *
     * Por eso se mira la DECLARACIÓN y no el documento entero: el comentario
     * de al lado nombra la forma rota para que nadie la reponga, y buscar en
     * todo el HTML se tropezaría con ese texto.
     */
    public function test_cada_formato_declara_su_tamano_de_papel(): void
    {
        $comprobante = $this->ventaConRecibo($this->turno())->comprobante;

        $ticket = $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', $comprobante));

        $this->assertMatchesRegularExpression(
            '/@page\s*\{\s*size:\s*80mm 200mm;\s*margin:\s*4mm;\s*\}/',
            $ticket->getContent(),
            'la regla @page del ticket no declara un tamaño de papel válido');

        // Y el alto de verdad lo calcula la hoja, para que la impresora de
        // rollo corte donde termina el ticket y no a los 200 mm.
        $ticket->assertSee("'@media print { @page { size: ' + ANCHO + 'mm '", false);

        $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', [$comprobante, 'formato' => 'a4']))
            ->assertSee('size: A4', false);
    }

    /**
     * La causa más común de sustitución: la factura salió con el NIT mal y se
     * corrige en la ficha del MISMO cliente. Comparando solo ids quedaba
     * bloqueada como «idéntica».
     */
    public function test_se_sustituye_para_corregir_el_nit_del_mismo_cliente(): void
    {
        $sesion = $this->turno();
        $empresa = $this->empresa();
        $factura = $this->ventaConRecibo($sesion, $empresa)->comprobante;
        $empresa->forceFill(['documento' => '99887766'])->save();

        $nuevo = Comprobantes::sustituir($factura->fresh()->load('venta'), $this->admin(), $empresa->fresh(), 'NIT mal escrito');

        $this->assertSame('99887766', $nuevo->cliente_documento);
        $this->assertSame('SUSTITUIDO', $factura->fresh()->estado);
    }

    public function test_sin_corregir_nada_el_mismo_cliente_sigue_siendo_identico(): void
    {
        $sesion = $this->turno();
        $empresa = $this->empresa();
        $factura = $this->ventaConRecibo($sesion, $empresa)->comprobante;

        $this->expectExceptionMessage('idéntico');
        Comprobantes::sustituir($factura->fresh()->load('venta'), $this->admin(), $empresa->fresh(), 'Nada');
    }

    /** En el modo sin procedimientos, el plazo no vencía nunca (días negativos). */
    public function test_el_plazo_tambien_vence_en_el_modo_php(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConRecibo($sesion);
        Venta::whereKey($venta->id)->update(['fecha' => now()->subDays(Comprobantes::plazoDias() + 2)]);

        $this->expectExceptionMessage('plazo');
        ReglasEnPhp::sustituirComprobante($venta->comprobante->id, $venta->comprobante->serie_id, null, $this->admin()->id, 'Tarde');
    }
}
