<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SerieComprobante;
use App\Models\SesionCaja;
use App\Models\TipoComprobante;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\ReglasEnPhp;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Throwable;

/**
 * Auditoría de normalización, bloque 3: la configuración con tipos y sin
 * claves foráneas escritas como texto, lo que sobraba del esquema, y los
 * reportes por jornada con «vendido» rotulado sin ambigüedad.
 */
class ConfiguracionYJornadaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        $admin = $this->admin();

        return (Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100))->fresh();
    }

    private function vender(?Cliente $cliente = null): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->admin(),
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 2]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    private function tipo(string $codigo): TipoComprobante
    {
        return TipoComprobante::where('codigo', $codigo)->firstOrFail();
    }

    private function serieNueva(string $codigo, string $serie): SerieComprobante
    {
        return SerieComprobante::create([
            'tipo_comprobante_id' => $this->tipo($codigo)->id, 'serie' => $serie,
            'correlativo_actual' => 0, 'longitud' => 6, 'activo' => 1,
        ]);
    }

    // ===================================================== A5 · la configuración

    /**
     * Las series eran dos claves de texto en `configuracion` —una FK sin FK— y
     * la de la nota de venta salía de otra consulta. Ahora es la serie por
     * omisión de cada tipo, y la base no deja que sea de otro tipo.
     */
    public function test_la_serie_de_cada_tipo_es_una_clave_foranea_de_su_mismo_tipo(): void
    {
        $claves = DB::table('configuracion')->pluck('clave')->all();
        foreach (['serie_factura', 'serie_recibo', 'moneda_simbolo'] as $clave) {
            $this->assertNotContains($clave, $claves);
        }

        foreach (['FAC', 'REC', 'NV'] as $codigo) {
            $this->assertNotNull($this->tipo($codigo)->seriePorOmision, "{$codigo} sin serie por omisión");
            $this->assertSame($this->tipo($codigo)->id, $this->tipo($codigo)->seriePorOmision->tipo_comprobante_id);
        }

        $deRecibos = $this->tipo('REC')->serie_por_omision_id;
        $this->assertThrows(
            fn () => DB::table('tipos_comprobante')->where('codigo', 'FAC')->update(['serie_por_omision_id' => $deRecibos]),
            QueryException::class,
            'fk_tipocomp_serie',
        );
    }

    /** Los tres tipos salen del mismo mecanismo, también la nota de venta. */
    public function test_la_venta_usa_la_serie_por_omision_de_su_tipo(): void
    {
        $r002 = $this->serieNueva('REC', 'R002');
        TipoComprobante::where('codigo', 'REC')->update(['serie_por_omision_id' => $r002->id]);
        $this->assertSame('R002', $this->vender()->comprobante->serie->serie);

        // La nota de venta ya no es «la primera serie activa»: es la elegida.
        config(['ventas.mostrar_facturacion' => false]);
        $nv02 = $this->serieNueva('NV', 'NV02');
        TipoComprobante::where('codigo', 'NV')->update(['serie_por_omision_id' => $nv02->id]);
        $empresa = Cliente::where('tipo_persona', 'JURIDICA')->where('activo', 1)->firstOrFail();
        $this->assertSame('NV02', $this->vender($empresa)->comprobante->serie->serie);

        // Una serie desactivada no numera: el mensaje dice dónde arreglarlo.
        $nv02->update(['activo' => 0]);
        $this->assertThrows(fn () => Ventas::seriePara($empresa), \RuntimeException::class, 'Sistema → Configuración');
    }

    /** La pantalla de configuración elige la serie en el tipo, y lo deja en la bitácora. */
    public function test_la_configuracion_guarda_la_serie_en_su_tipo(): void
    {
        $r002 = $this->serieNueva('REC', 'R002');
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');

        $this->actingAs($this->admin())->put(route('configuracion.update'), [
            'negocio_nombre' => $valor('negocio_nombre'),
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'cobra_impuesto' => '1',
            'tasa_impuesto' => '13',
            'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto'),
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
            'serie_factura' => (string) $this->tipo('FAC')->serie_por_omision_id,
            'serie_recibo' => (string) $r002->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('exito');

        $this->assertSame($r002->id, $this->tipo('REC')->serie_por_omision_id);
        $this->assertNull(DB::table('configuracion')->where('clave', 'serie_recibo')->value('valor'));

        $rastro = json_decode((string) DB::table('auditoria')->where('accion', 'CONFIGURACION_ACTUALIZADA')
            ->orderByDesc('id')->value('detalle'), true);
        $this->assertSame((string) $r002->id, $rastro['serie_recibo']['despues']);
    }

    /** El símbolo ya no se guarda: sale del código, y no pueden quedar desparejos. */
    public function test_el_simbolo_de_la_moneda_sale_del_codigo(): void
    {
        DB::table('configuracion')->where('clave', 'moneda_codigo')->update(['valor' => 'USD']);
        Config::olvidar();

        $this->assertSame('$', Config::moneda());
        $this->assertSame('$ 1,200.00', Config::importe(1200));
    }

    /** Donde el tipo importa, la base rechaza un valor mal escrito: no llega a los cálculos. */
    public function test_la_configuracion_rechaza_valores_que_no_son_de_su_tipo(): void
    {
        $malos = [
            'ck_config_banderas' => [['precios_incluyen_impuesto', 'si'], ['exigir_referencia_pago', '2']],
            'ck_config_tasa' => [['tasa_impuesto', '13'], ['tasa_impuesto', 'trece'], ['tasa_impuesto', '-0.1']],
            'ck_config_hora' => [['hora_corte_jornada', '13'], ['hora_corte_jornada', '5.5']],
            'ck_config_descuento' => [['descuento_max_cajero', '101'], ['descuento_max_cajero', '10%']],
            'ck_config_dias' => [['dias_max_sustitucion', '-1'], ['dias_max_sustitucion', 'uno']],
            'ck_config_egreso' => [['egreso_max_cajero', '-5'], ['egreso_max_cajero', '10.555']],
            'ck_config_moneda' => [['moneda_codigo', 'bob'], ['moneda_codigo', 'BS'], ['moneda_codigo', '']],
        ];

        foreach ($malos as $check => $casos) {
            foreach ($casos as [$clave, $valor]) {
                try {
                    DB::table('configuracion')->where('clave', $clave)->update(['valor' => $valor]);
                    $this->fail("La base aceptó {$clave} = «{$valor}».");
                } catch (QueryException $e) {
                    $this->assertStringContainsString($check, $e->getMessage(), "{$clave} = «{$valor}»");
                }
            }
        }

        // Lo válido pasa.
        foreach ([['tasa_impuesto', '0'], ['tasa_impuesto', '0.1300'], ['hora_corte_jornada', '12'], ['egreso_max_cajero', '200'], ['moneda_codigo', 'USD']] as [$clave, $valor]) {
            DB::table('configuracion')->where('clave', $clave)->update(['valor' => $valor]);
            $this->assertSame($valor, DB::table('configuracion')->where('clave', $clave)->value('valor'));
        }
    }

    // ================================================ A6 · lo que sobraba

    public function test_no_quedan_columnas_vistas_ni_indices_sin_uso(): void
    {
        $this->assertFalse(Schema::hasColumn('pedidos', 'telefono_cliente'));
        $this->assertFalse(Schema::hasColumn('comprobantes', 'archivo_pdf'));

        $vistas = DB::table('information_schema.VIEWS')->where('TABLE_SCHEMA', DB::getDatabaseName())->pluck('TABLE_NAME')->all();
        foreach (['v_empleados', 'v_ventas_comprobante', 'v_comprobantes_sustituidos', 'v_comprobantes_emitidos'] as $vista) {
            $this->assertNotContains($vista, $vistas);
        }

        $indices = collect(DB::select('SHOW INDEX FROM venta_detalle'))->pluck('Key_name')->unique();
        $this->assertFalse($indices->contains('ix_detalle_venta'), 'ix_detalle_venta lo cubre uq_detalle_venta_producto');
        $this->assertTrue($indices->contains('uq_detalle_venta_producto'));
    }

    /**
     * El detalle, los pagos y las líneas del pedido ya no se borran en cascada
     * con su padre: sin triggers (LOGICA_EN_PHP) un DELETE a mano de una venta
     * se llevaba su detalle y sus pagos sin que nada lo impidiera.
     */
    public function test_el_detalle_no_se_borra_en_cascada_con_su_venta(): void
    {
        $reglas = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('CONSTRAINT_NAME', ['fk_detalle_venta', 'fk_pagos_venta', 'fk_pedidodet_pedido'])
            ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')->all();

        $this->assertSame(['fk_detalle_venta' => 'RESTRICT', 'fk_pagos_venta' => 'RESTRICT', 'fk_pedidodet_pedido' => 'RESTRICT'], collect($reglas)->sortKeys()->all());

        $venta = $this->vender();
        $this->assertThrows(fn () => DB::table('ventas')->where('id', $venta->id)->delete(), QueryException::class);
        $this->assertSame(1, DB::table('venta_detalle')->where('venta_id', $venta->id)->count());
    }

    /**
     * La tasa de cada línea la pone la base (o su réplica), siempre: antes una
     * tasa explícita en el INSERT mandaba, y un INSERT a mano ponía cualquiera.
     */
    public function test_la_tasa_de_la_linea_no_la_decide_quien_inserta(): void
    {
        // Una venta a medio armar, sin comprobante: la única que admite líneas.
        $vendida = $this->vender();
        $ventaId = DB::table('ventas')->insertGetId([
            'usuario_id' => $vendida->usuario_id, 'sesion_caja_id' => $vendida->sesion_caja_id,
        ]);
        $plato = Producto::where('codigo', 'P-0009')->firstOrFail();
        $tasa = Config::tasaImpuesto();
        $linea = [
            'venta_id' => $ventaId, 'producto_id' => $plato->id, 'descripcion' => $plato->nombre,
            'cantidad' => 1, 'precio_unitario' => $plato->precio_venta, 'tasa_impuesto' => 0.5,
        ];

        if (ReglasEnPhp::activa()) {
            $this->assertSame($tasa, (float) ReglasEnPhp::antesDeInsertarLineaVenta($linea)['tasa_impuesto']);

            return;
        }

        DB::table('venta_detalle')->insert($linea);
        $this->assertSame($tasa, (float) DB::table('venta_detalle')->where('venta_id', $ventaId)->where('producto_id', $plato->id)->value('tasa_impuesto'));
    }

    /**
     * Lo cerrado no crece: a una venta que ya tiene su comprobante, o anulada,
     * la base no le deja agregar líneas ni pagos, y nada documentado se borra.
     * Solo en la base: la aplicación nunca lo intenta.
     */
    public function test_lo_documentado_no_se_toca_por_fuera_de_la_aplicacion(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Es una guarda de la base: sin triggers no hay contra qué probar.');
        }

        $venta = $this->vender();
        $plato = Producto::where('codigo', 'P-0009')->firstOrFail();

        $this->assertThrows(fn () => DB::table('venta_detalle')->insert([
            'venta_id' => $venta->id, 'producto_id' => $plato->id, 'descripcion' => $plato->nombre,
            'cantidad' => 1, 'precio_unitario' => $plato->precio_venta,
        ]), QueryException::class, 'no admite más líneas');
        $this->assertThrows(fn () => DB::table('venta_pagos')->insert([
            'venta_id' => $venta->id, 'metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => 1,
        ]), QueryException::class, 'no admite más pagos');

        foreach (['comprobantes', 'venta_detalle', 'venta_pagos'] as $tabla) {
            $this->assertThrows(fn () => DB::table($tabla)->where('venta_id', $venta->id)->delete(), QueryException::class);
        }
        $this->assertSame(1, DB::table('comprobantes')->where('venta_id', $venta->id)->count());
    }

    /** A7: el comentario del precio ya no afirma algo que es falso con el impuesto incluido. */
    public function test_el_esquema_explica_que_el_precio_depende_del_modo(): void
    {
        $esquema = (string) file_get_contents(base_path('../docs/sql/01_schema_mysql.sql'));

        $this->assertStringNotContainsString('-- precio SIN impuesto (base imponible)', $esquema);
        $this->assertStringContainsString('depende de `configuracion.precios_incluyen_impuesto`', $esquema);
    }

    // ================================================ C6 · por jornada

    /**
     * El local cierra pasada la medianoche: la venta de la 01:30 es de la
     * noche anterior en los reportes, en su vista, en el listado de ventas y
     * en la portada —igual que el número de su pedido—. Antes contaba para el
     * día de calendario.
     */
    public function test_una_venta_de_la_madrugada_cuenta_en_la_jornada_anterior(): void
    {
        $this->assertSame(5, Config::horaCorteJornada());
        $venta = $this->vender();
        DB::table('ventas')->where('id', $venta->id)->update(['fecha' => '2031-05-10 01:30:00']);
        $total = (float) $venta->fresh()->total;

        $reporte = fn (string $dia) => $this->actingAs($this->admin())
            ->get(route('reportes.ventas', ['desde' => $dia, 'hasta' => $dia]))->assertOk();

        $noche = $reporte('2031-05-09');
        $this->assertSame(1, $noche->viewData('resumen')['operaciones']);
        $this->assertSame($total, $noche->viewData('porDia')[0]['monto']);
        $this->assertSame(0, $reporte('2031-05-10')->viewData('resumen')['operaciones']);

        if (DB::table('information_schema.VIEWS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'v_ventas_por_dia')->exists()) {
            $this->assertSame($total, (float) DB::table('v_ventas_por_dia')->where('dia', '2031-05-09')->value('monto_total'));
            $this->assertFalse(DB::table('v_ventas_por_dia')->where('dia', '2031-05-10')->exists());
        }

        // El detalle por jornada enlaza al listado de ventas, que filtra igual.
        $this->actingAs($this->admin())->get(route('ventas.index', ['desde' => '2031-05-09', 'hasta' => '2031-05-09']))
            ->assertOk()->assertViewHas('ventas', fn ($lista) => $lista->contains('id', $venta->id));

        // La portada: a las 02:00 del 10 todavía es la jornada del 9.
        try {
            Carbon::setTestNow('2031-05-10 02:00:00');
            $hoy = $this->actingAs($this->admin())->get(route('inicio'))->viewData('hoy');
            $this->assertSame($total, $hoy['hoy']['monto']);

            Carbon::setTestNow('2031-05-10 06:00:00');
            $hoy = $this->actingAs($this->admin())->get(route('inicio'))->viewData('hoy');
            $this->assertSame(0.0, $hoy['hoy']['monto']);
            $this->assertSame($total, $hoy['ayer']['monto']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ================================================ C7 · qué «vendido» es cuál

    /**
     * Con impuesto, «vendido» dice dos cifras distintas: en el reporte de
     * ventas, lo cobrado con el impuesto; en el ranking del menú, la base sin
     * él. El rótulo lo dice, en pantalla y en lo que se descarga.
     */
    public function test_cada_vendido_dice_si_lleva_impuesto(): void
    {
        $this->assertGreaterThan(0, Config::tasaImpuesto());
        $this->vender();

        $this->actingAs($this->admin())->get(route('reportes.ventas'))->assertOk()
            ->assertSee('data-rotulo-vendido', false)
            ->assertSee('Vendido (con impuesto)');
        $this->actingAs($this->admin())->get(route('reportes.productos'))->assertOk()
            ->assertSee('Vendido sin impuesto');

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.productos.excel'))->assertOk();
        $fichero = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($fichero, $respuesta->streamedContent());

        try {
            $textos = collect(IOFactory::load($fichero)->getAllSheets())
                ->flatMap(fn ($hoja) => collect($hoja->toArray())->flatten())
                ->filter()->all();
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        } finally {
            @unlink($fichero);
        }

        $this->assertContains('Vendido sin impuesto', $textos);

        // Con tasa cero coinciden: basta la palabra.
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();
        $this->actingAs($this->admin())->get(route('reportes.productos'))->assertOk()
            ->assertDontSee('Vendido sin impuesto');
    }
}
