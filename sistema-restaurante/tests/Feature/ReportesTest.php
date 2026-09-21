<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ReportesTest extends TestCase
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

    private function cocina(): Usuario
    {
        return Usuario::where('usuario', 'cocina1')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), 200);
    }

    private function vender(SesionCaja $sesion, float $cantidad = 2, string $codigo = 'P-0004', float $descuento = 0): Venta
    {
        $producto = Producto::where('codigo', $codigo)->firstOrFail();

        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [[
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) $producto->precio_venta,
            ]],
            descuento: $descuento,
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    /** La jornada en curso: a la 01:30 todavía es la de ayer, y es la que cuenta lo que se vende ahora. */
    private function hoy(): array
    {
        return ['desde' => Config::jornadaActual(), 'hasta' => Config::jornadaActual()];
    }

    /**
     * Tres pruebas de aquí comparan lo que calcula la aplicación contra las
     * vistas del esquema, para que las fórmulas no se separen. Esa comparación
     * solo tiene sentido donde las vistas existen: un hosting compartido puede
     * denegar `CREATE VIEW` (InfinityFree lo hace, error 1142) y ahí la
     * aplicación consulta las tablas base por su cuenta. En ese caso no hay
     * nada contra qué comparar, y saltarse la prueba es más honesto que
     * fingir que pasó.
     */
    private function requiereLasVistas(): void
    {
        $hay = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'
        )->n;

        if (! $hay) {
            $this->markTestSkipped('La base no tiene vistas: no hay contra qué comparar.');
        }
    }

    // -------------------------------------------------------------- permisos

    /** `reportes.ver` lo tiene el administrador, no el cajero. */
    public function test_el_cajero_no_entra_a_los_reportes(): void
    {
        $this->actingAs($this->cajero())->get('/reportes/ventas')->assertForbidden();
        $this->actingAs($this->cajero())->get('/reportes/productos')->assertForbidden();
    }

    /** Los reportes muestran las ventas de todos los cajeros: solo los ve el administrador. */
    public function test_solo_el_administrador_ve_los_reportes(): void
    {
        $this->actingAs($this->admin())->get('/reportes/ventas')->assertOk();
        $this->actingAs($this->admin())->get('/reportes/productos')->assertOk();

        foreach ([$this->cocina(), $this->cajero()] as $usuario) {
            $this->actingAs($usuario)->get('/reportes/ventas')->assertForbidden();
            $this->actingAs($usuario)->get('/reportes/productos')->assertForbidden();
        }
    }

    // ------------------------------------------------------ reporte de ventas

    public function test_el_resumen_cuenta_lo_vendido_del_periodo(): void
    {
        $sesion = $this->turno();
        $primera = $this->vender($sesion, 2);
        $segunda = $this->vender($sesion, 3);

        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertOk()
            ->viewData('resumen');

        $esperado = round((float) $primera->fresh()->total + (float) $segunda->fresh()->total, 2);

        $this->assertSame(2, $resumen['operaciones']);
        $this->assertSame($esperado, $resumen['vendido']);
        $this->assertSame(round($esperado / 2, 2), $resumen['ticket']);
    }

    public function test_una_venta_anulada_no_suma_al_vendido(): void
    {
        $sesion = $this->turno();
        $vigente = $this->vender($sesion, 2);
        $anulada = $this->vender($sesion, 3);

        Ventas::anular($anulada, $this->admin(), 'Error de cobro');

        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->viewData('resumen');

        $this->assertSame(1, $resumen['operaciones']);
        $this->assertSame(1, $resumen['anuladas']);
        $this->assertSame((float) $vigente->fresh()->total, $resumen['vendido']);
    }

    /** Una venta de Bs 100 con 10 % de descuento no aparece en el ranking como 100. */
    public function test_mas_vendidos_reparte_el_descuento_de_la_venta(): void
    {
        $sesion = $this->turno();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $venta = Ventas::registrar(
            sesion: $sesion, usuario: $this->admin(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 10]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            descuento: 3.54,
        );

        $fila = $this->actingAs($this->admin())
            ->get(route('reportes.productos', $this->hoy()))
            ->viewData('masVendidos')
            ->firstWhere('id', $producto->id);

        $this->assertSame(round((float) $venta->subtotal - 3.54, 2), round((float) $fila->monto_vendido, 2));
    }

    /** Sin rellenar los huecos, el gráfico uniría días lejanos con una recta. */
    public function test_la_serie_diaria_rellena_los_dias_sin_ventas(): void
    {
        $this->turno();

        $serie = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subDays(6)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->viewData('porDia');

        $this->assertCount(7, $serie);
        $this->assertSame(now()->subDays(6)->toDateString(), $serie[0]['dia']);
        $this->assertSame(now()->toDateString(), $serie[6]['dia']);
    }

    public function test_el_desglose_por_metodo_de_pago_cuadra_con_lo_vendido(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.ventas', $this->hoy()));

        $porMetodo = $respuesta->viewData('porMetodo');

        $this->assertSame('Efectivo', $porMetodo->first()->metodo_pago);
        $this->assertSame(
            (float) $venta->fresh()->total,
            round((float) $porMetodo->sum('monto'), 2),
        );
    }

    /**
     * `porDia()` y `porMetodoPago()` repiten las fórmulas de `v_ventas_por_dia`
     * y `v_ventas_por_metodo_pago` contra las tablas base (filtrando antes de
     * agrupar, para que el rango use el índice de fecha en vez de forzar un
     * recorrido completo — ver el comentario en el controlador). Esta prueba
     * compara ambas sobre todo el histórico para que las fórmulas no se
     * separen con el tiempo, igual que ya hace el ranking de productos con su
     * propia vista.
     */
    public function test_la_serie_y_el_desglose_coinciden_con_las_vistas_del_esquema(): void
    {
        $this->requiereLasVistas();

        $sesion = $this->turno();
        $this->vender($sesion, 2);
        $this->vender($sesion, 1, 'P-0009');

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.ventas', [
            'desde' => now()->subYears(5)->toDateString(),
            'hasta' => now()->toDateString(),
        ]));

        $serie = collect($respuesta->viewData('porDia'))->keyBy('dia');
        $vistaPorDia = DB::table('v_ventas_por_dia')->get()->keyBy(fn ($f) => (string) $f->dia);

        foreach ($vistaPorDia as $dia => $fila) {
            $this->assertArrayHasKey($dia, $serie, "Falta el día {$dia} en la serie.");
            $this->assertSame($fila->cantidad_ventas, $serie[$dia]['ventas']);
            $this->assertSame((float) $fila->monto_total, $serie[$dia]['monto']);
            $this->assertSame((float) $fila->ticket_promedio, $serie[$dia]['ticket']);
        }

        $porMetodo = $respuesta->viewData('porMetodo')->keyBy('metodo_pago');
        $vistaPorMetodo = DB::table('v_ventas_por_metodo_pago')
            ->selectRaw('metodo_pago, SUM(cantidad_ventas) AS ventas, SUM(monto) AS monto')
            ->groupBy('metodo_pago')
            ->get()
            ->keyBy('metodo_pago');

        foreach ($vistaPorMetodo as $metodo => $fila) {
            $this->assertArrayHasKey($metodo, $porMetodo, "Falta el método «{$metodo}».");
            $this->assertSame((int) $fila->ventas, (int) $porMetodo[$metodo]->ventas);
            $this->assertSame((float) $fila->monto, (float) $porMetodo[$metodo]->monto);
        }
    }

    public function test_el_desglose_por_cajero_identifica_a_quien_vendio(): void
    {
        $sesion = $this->turno($this->cajero());
        $this->vender($sesion, 2);

        $porCajero = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->viewData('porCajero');

        $fila = collect($porCajero)->firstWhere('usuario', 'cajero1');

        $this->assertNotNull($fila);
        $this->assertSame(1, (int) $fila->ventas);
    }

    /** Un rango al revés se endereza en vez de devolver vacío. */
    public function test_un_rango_invertido_se_endereza(): void
    {
        $respuesta = $this->actingAs($this->admin())->get(route('reportes.ventas', [
            'desde' => now()->toDateString(),
            'hasta' => now()->subDays(5)->toDateString(),
        ]))->assertOk();

        $this->assertTrue($respuesta->viewData('desde')->lte($respuesta->viewData('hasta')));
        $this->assertCount(6, $respuesta->viewData('porDia'));
    }

    public function test_sin_ventas_el_reporte_responde_en_cero(): void
    {
        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subYears(3)->toDateString(),
                'hasta' => now()->subYears(3)->addDays(2)->toDateString(),
            ]))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(0, $resumen['operaciones']);
        $this->assertSame(0.0, $resumen['vendido']);
        $this->assertSame(0.0, $resumen['ticket']);
    }

    // --------------------------------------------------- reporte de productos

    /**
     * El ranking del reporte es `v_productos_mas_vendidos` con filtro de
     * fechas: la vista no admite rango. Esta prueba compara ambos sobre todo
     * el histórico para que las fórmulas no se separen con el tiempo.
     */
    public function test_el_ranking_coincide_con_la_vista_del_esquema(): void
    {
        $this->requiereLasVistas();

        $sesion = $this->turno();
        $conDescuento = $this->vender($sesion, 3, 'P-0004', descuento: 1.00);
        $this->vender($sesion, 2, 'P-0009');

        // Con un descuento de cabecera de por medio: es justo donde las dos
        // fórmulas podrían separarse sin que nadie se entere.
        $this->assertGreaterThan(0, (float) $conDescuento->fresh()->descuento_visible);

        $reporte = $this->actingAs($this->admin())
            ->get(route('reportes.productos', [
                'desde' => now()->subYears(5)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->viewData('masVendidos')
            ->keyBy('id');

        $vista = DB::table('v_productos_mas_vendidos')->get()->keyBy('id');

        $this->assertNotEmpty($reporte);

        foreach ($reporte as $id => $fila) {
            $this->assertArrayHasKey($id, $vista, "El producto {$id} no está en la vista.");

            $this->assertSame($vista[$id]->unidades_vendidas, $fila->unidades_vendidas);
            $this->assertSame($vista[$id]->monto_vendido, $fila->monto_vendido);
        }
    }

    /**
     * «Vendido» mezcla tarjeta y QR; la línea de efectivo tiene que dar lo
     * mismo que los arqueos, sin el monto inicial.
     */
    public function test_el_efectivo_en_cajas_cuadra_con_el_arqueo(): void
    {
        $resumen = fn () => $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))->viewData('resumen');
        $antes = $resumen()['efectivo'];

        $sesion = $this->turno();
        $venta = $this->vender($sesion, 3);
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        Ventas::registrar(
            sesion: $sesion, usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('afecta_caja', 0)->value('id'), 'monto' => null, 'referencia' => 'VOUCHER-001']],
        );
        Cajas::movimiento($sesion->fresh(), $this->admin(), 'INGRESO', 'Cambio del banco', 30);
        Cajas::movimiento($sesion->fresh(), $this->admin(), 'EGRESO', 'Bolsas', 10);
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $cerrada = $sesion->fresh();
        $despues = $resumen();

        $this->assertSame(
            round((float) $cerrada->monto_esperado - (float) $cerrada->monto_inicial, 2),
            round($despues['efectivo'] - $antes, 2),
        );
        $this->assertNotSame($despues['vendido'], $despues['efectivo']);
        $this->actingAs($this->admin())->get(route('reportes.ventas', $this->hoy()))->assertSee('Efectivo que pasó por las cajas');
    }

    // ------------------------------------------------------------- interfaz

    /** El rango se anuncia en días enteros, ambos extremos incluidos. */
    public function test_la_pantalla_anuncia_los_dias_del_rango(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subDays(6)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('(7 días)');

        // Un solo día se anuncia en singular, y sin decimales sueltos.
        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertSee('(1 día)');
    }

    /** El gráfico se dibuja desde el JSON que deja la plantilla. */
    public function test_la_pantalla_publica_los_datos_del_grafico(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertOk()
            ->assertSee('data-apexchart', false)
            ->assertSee('Ventas por jornada', false);
    }

    public function test_el_menu_ofrece_los_reportes_a_quien_puede_verlos(): void
    {
        $this->actingAs($this->admin())
            ->get('/perfil')
            ->assertSee('href="'.url('/reportes/ventas').'"', false);

        $this->actingAs($this->cajero())
            ->get('/perfil')
            ->assertDontSee('href="'.url('/reportes/ventas').'"', false);
    }

    // ------------------------------------------------------------ exportación

    /** Guarda la descarga en un fichero temporal y lo abre como libro real. */
    private function libroDescargado(string $ruta): Spreadsheet
    {
        $respuesta = $this->actingAs($this->admin())->get($ruta);
        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $fichero = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($fichero, $respuesta->streamedContent());

        try {
            return IOFactory::load($fichero);
        } finally {
            @unlink($fichero);
        }
    }

    public function test_el_reporte_de_ventas_se_descarga_como_libro_de_excel(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $libro = $this->libroDescargado(route('reportes.ventas.excel', $this->hoy()));

        $this->assertSame(
            ['Resumen', 'Ventas por jornada', 'Por método de pago', 'Por cajero'],
            collect($libro->getAllSheets())->map(fn ($h) => $h->getTitle())->all()
        );

        // Cabecera del documento: sin ella no se sabe de qué negocio es.
        $this->assertSame('Restaurante El Buen Sabor', $libro->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_el_reporte_de_productos_se_descarga_como_libro_de_excel(): void
    {
        $libro = $this->libroDescargado(route('reportes.productos.excel'));

        $this->assertSame(
            ['Resumen', 'Más vendidos'],
            collect($libro->getAllSheets())->map(fn ($h) => $h->getTitle())->all()
        );
    }

    /**
     * Lo que se descarga tiene que servir para sumar y ordenar: los importes van
     * como números y los días como fechas, no como texto ya formateado.
     */
    public function test_los_importes_y_las_fechas_van_como_datos_y_no_como_texto(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $hoja = $this->libroDescargado(route('reportes.ventas.excel', $this->hoy()))
            ->getSheetByName('Ventas por jornada');

        // Filas 1-3 la cabecera del documento, 4 en blanco, 5 el título, 6 la
        // nota y 7 los nombres de columna: los datos empiezan en la 8.
        $this->assertSame('Jornada', $hoja->getCell('A7')->getValue());

        $this->assertIsNumeric($hoja->getCell('D8')->getValue(), 'el monto debería ser un número');
        $this->assertSame('#,##0.00', $hoja->getStyle('D8')->getNumberFormat()->getFormatCode());

        $this->assertIsNumeric($hoja->getCell('A8')->getValue(), 'el día debería ser una fecha de Excel');
        $this->assertSame('dd/mm/yyyy', $hoja->getStyle('A8')->getNumberFormat()->getFormatCode());
    }

    /** Los días sin una sola venta no se exportan: eran filas de ceros. */
    public function test_el_excel_solo_trae_los_dias_con_movimiento(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $hoja = $this->libroDescargado(route('reportes.ventas.excel', ['desde' => now()->subDays(20)->toDateString(), 'hasta' => now()->toDateString()]))
            ->getSheetByName('Ventas por jornada');

        // Cabecera en la 7, una fila de datos y una de totales: 9 en total.
        // Con los 21 días del rango serían 29.
        $this->assertLessThan(15, $hoja->getHighestRow());
    }

    public function test_los_reportes_se_descargan_como_pdf(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        foreach (['reportes.ventas.pdf', 'reportes.productos.pdf'] as $ruta) {
            $respuesta = $this->actingAs($this->admin())->get(route($ruta));

            $respuesta->assertOk();
            $respuesta->assertHeader('content-type', 'application/pdf');

            // dompdf devuelve la respuesta entera, no un stream como el Excel.
            $bytes = $respuesta->getContent();
            $this->assertStringStartsWith('%PDF-', $bytes, "«{$ruta}» no devolvió un PDF");
            $this->assertStringContainsString('%%EOF', substr($bytes, -2048), "«{$ruta}» devolvió un PDF truncado");
        }
    }

    /** El rango de la pantalla es el que se lleva la descarga. */
    public function test_la_descarga_respeta_el_rango_de_fechas(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->get(route('reportes.ventas.excel', ['desde' => '2026-01-10', 'hasta' => '2026-01-20']));

        $respuesta->assertOk();
        $respuesta->assertHeader('content-disposition', 'attachment; filename=reporte-ventas_2026-01-10_2026-01-20.xlsx');
    }

    public function test_quien_no_puede_ver_reportes_tampoco_puede_descargarlos(): void
    {
        foreach (['reportes.ventas.excel', 'reportes.productos.excel', 'reportes.ventas.pdf', 'reportes.productos.pdf'] as $ruta) {
            $this->actingAs($this->cajero())
                ->get(route($ruta))
                ->assertForbidden();
        }
    }
}
