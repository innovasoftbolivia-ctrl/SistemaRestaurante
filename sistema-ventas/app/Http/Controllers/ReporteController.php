<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\Hojas;
use App\Support\Config;
use App\Support\TopeExcel;
use App\Support\TopePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes de gestión (objetivo O5).
 *
 * Los agregados salen de las vistas que ya define `docs/sql`
 * (`v_ventas_por_dia`, `v_ventas_por_metodo_pago`, `v_alertas_stock`): son la
 * definición oficial de cada cifra y no se reescriben aquí.
 */
class ReporteController extends Controller
{
    public function ventas(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.ventas', [
            'title' => 'Reporte de ventas',
            'desde' => $desde,
            'hasta' => $hasta,
            'resumen' => $this->resumenVentas($desde, $hasta),
            'variacion' => $this->variacionVendido($desde, $hasta),
            'porDia' => $this->porDia($desde, $hasta),
            'porMetodo' => $this->porMetodoPago($desde, $hasta),
            'porCajero' => $this->porCajero($desde, $hasta),
        ]);
    }

    public function productos(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.productos', [
            'title' => 'Reporte de productos e inventario',
            'desde' => $desde,
            'hasta' => $hasta,
            'masVendidos' => $this->masVendidos($desde, $hasta),
            'alertas' => Producto::alertasDeStock()->orderByDesc('faltante')->get(),
            'inventario' => $this->valorInventario(),
        ]);
    }

    // -------------------------------------------------------------- exportación

    public function ventasExcel(Request $request): StreamedResponse|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->excel($this->documentoVentas($desde, $hasta), $this->nombreFichero('ventas', $desde, $hasta, 'xlsx'));
    }

    public function productosExcel(Request $request): StreamedResponse|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->excel($this->documentoProductos($desde, $hasta), $this->nombreFichero('productos', $desde, $hasta, 'xlsx'));
    }

    private function excel(array $documento, string $nombreFichero): StreamedResponse|RedirectResponse
    {
        if ($aviso = TopeExcel::excedido($documento)) {
            return back()->with('error', $aviso);
        }

        return (new Hojas($documento))->descargar($nombreFichero);
    }

    public function ventasPdf(Request $request): Response|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->pdf($this->documentoVentas($desde, $hasta), $this->nombreFichero('ventas', $desde, $hasta, 'pdf'));
    }

    public function productosPdf(Request $request): Response|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->pdf($this->documentoProductos($desde, $hasta), $this->nombreFichero('productos', $desde, $hasta, 'pdf'));
    }

    /**
     * El PDF sale de la misma estructura que el Excel, así que las dos
     * descargas no se pueden separar con el tiempo.
     */
    private function pdf(array $documento, string $nombreFichero): Response|RedirectResponse
    {
        if ($aviso = TopePdf::excedido($documento)) {
            return back()->with('error', $aviso);
        }

        return Pdf::loadView('reportes.pdf', ['doc' => $documento])
            ->setPaper('a4', $documento['orientacion'] ?? 'portrait')
            ->download($nombreFichero);
    }

    // ------------------------------------------------- contenido de cada reporte

    /**
     * Qué lleva el reporte de ventas.
     *
     * Se exporta lo que sirve para decidir, no todo lo que hay: los días sin
     * una sola venta se quedan fuera —eran treinta filas de ceros— y el
     * impuesto solo aparece si el negocio lo desglosa.
     *
     * @return array<string, mixed>
     */
    private function documentoVentas(Carbon $desde, Carbon $hasta): array
    {
        $resumen = $this->resumenVentas($desde, $hasta);
        $tasa = Config::tasaImpuesto();

        $dias = collect($this->porDia($desde, $hasta))->filter(fn ($d) => $d['ventas'] > 0)->values();
        $metodos = $this->porMetodoPago($desde, $hasta);
        $cajeros = $this->porCajero($desde, $hasta);

        $totalMetodos = (float) $metodos->sum('monto');
        $totalCajeros = (float) $cajeros->sum('monto');

        $indicadores = [
            ['etiqueta' => 'Operaciones', 'valor' => $resumen['operaciones'], 'formato' => 'entero', 'nota' => 'ventas cobradas, sin contar anuladas'],
            ['etiqueta' => 'Vendido', 'valor' => $resumen['vendido'], 'formato' => 'moneda', 'nota' => 'suma de los totales cobrados'],
            ['etiqueta' => 'Devuelto', 'valor' => $resumen['devuelto'], 'formato' => 'moneda', 'nota' => 'devoluciones registradas en el período; es lo que salió del cajón'],
            ['etiqueta' => 'Neto', 'valor' => $resumen['neto'], 'formato' => 'moneda', 'nota' => 'lo vendido en el período menos lo que se devolvió de esas ventas, aunque se haya devuelto después', 'destacar' => true],
            ['etiqueta' => 'Efectivo en cajas', 'valor' => $resumen['efectivo'], 'formato' => 'moneda', 'nota' => 'ventas y devoluciones en efectivo, más ingresos y menos egresos: cuadra con los arqueos, sin el monto inicial'],
            ['etiqueta' => 'Ticket promedio', 'valor' => $resumen['ticket'], 'formato' => 'moneda', 'nota' => 'vendido entre operaciones'],
            ['etiqueta' => 'Ventas anuladas', 'valor' => $resumen['anuladas'], 'formato' => 'entero', 'nota' => 'revirtieron su stock'],
        ];

        if ($tasa > 0 && Config::facturacionVisible()) {
            $indicadores[] = ['etiqueta' => 'Impuesto', 'valor' => $resumen['impuesto'], 'formato' => 'moneda', 'nota' => 'incluido en lo vendido'];
        }

        return $this->documento('Reporte de ventas', $desde, $hasta, $indicadores, [
            [
                'nombre' => 'Ventas por día',
                'nota' => 'Solo los días con movimiento.',
                'cabeceras' => ['Día', 'Ventas', 'Ticket promedio', 'Monto'],
                'formatos' => ['fecha', 'entero', 'moneda', 'moneda'],
                'alineacion' => ['izq', 'der', 'der', 'der'],
                'filas' => $dias->map(fn ($d) => [$d['dia'], $d['ventas'], $d['ticket'], $d['monto']])->all(),
                'totales' => ['Total', $dias->sum('ventas'), null, $dias->sum('monto')],
                'vacia' => 'No hubo ventas en el período.',
            ],
            [
                'nombre' => 'Por método de pago',
                'cabeceras' => ['Método de pago', 'Ventas', 'Monto', '% del total'],
                'formatos' => [null, 'entero', 'moneda', 'porcentaje'],
                'alineacion' => ['izq', 'der', 'der', 'der'],
                'filas' => $metodos->map(fn ($f) => [
                    $f->metodo_pago,
                    (int) $f->ventas,
                    (float) $f->monto,
                    $totalMetodos > 0 ? (float) $f->monto / $totalMetodos : 0,
                ])->all(),
                'totales' => ['Total', (int) $metodos->sum('ventas'), $totalMetodos, $totalMetodos > 0 ? 1.0 : 0],
                'vacia' => 'No se registraron cobros en el período.',
            ],
            [
                'nombre' => 'Por cajero',
                'nota' => 'Quién cobró cada venta. No incluye las anuladas.',
                'cabeceras' => ['Cajero', 'Usuario', 'Ventas', 'Ticket', 'Monto', '% del total'],
                'formatos' => [null, null, 'entero', 'moneda', 'moneda', 'porcentaje'],
                'alineacion' => ['izq', 'izq', 'der', 'der', 'der', 'der'],
                'filas' => $cajeros->map(fn ($f) => [
                    $f->empleado,
                    $f->usuario,
                    (int) $f->ventas,
                    (float) $f->ticket,
                    (float) $f->monto,
                    $totalCajeros > 0 ? (float) $f->monto / $totalCajeros : 0,
                ])->all(),
                'totales' => ['Total', null, (int) $cajeros->sum('ventas'), null, $totalCajeros, $totalCajeros > 0 ? 1.0 : 0],
                'vacia' => 'Nadie registró ventas en el período.',
            ],
        ]);
    }

    /**
     * Qué lleva el reporte de productos: qué hay que reponer y qué se vende.
     *
     * @return array<string, mixed>
     */
    private function documentoProductos(Carbon $desde, Carbon $hasta): array
    {
        $inventario = $this->valorInventario();
        $alertas = Producto::alertasDeStock()->orderByDesc('faltante')->get();
        $ranking = $this->masVendidos($desde, $hasta)->values();

        // El porcentaje, contra todo lo vendido en el período y no contra los
        // veinte de la tabla: con cientos de productos, esos veinte sumaban
        // siempre 100 %.
        $totalVendido = $this->totalVendidoNeto($desde, $hasta);

        $indicadores = [
            ['etiqueta' => 'Productos activos', 'valor' => $inventario['productos'], 'formato' => 'entero', 'nota' => 'en catálogo'],
            ['etiqueta' => 'Inventario a costo', 'valor' => $inventario['costo'], 'formato' => 'moneda', 'nota' => $inventario['inactivos_con_stock'] > 0
                ? "lo que costó lo que hay en estante, con {$inventario['inactivos_con_stock']} producto(s) dado(s) de baja que aún tienen stock"
                : 'lo que costó lo que hay en estante'],
            ['etiqueta' => 'Inventario a venta', 'valor' => $inventario['venta'], 'formato' => 'moneda', 'nota' => 'lo que se cobraría por todo'],
            ['etiqueta' => 'Margen potencial', 'valor' => $inventario['margen'], 'formato' => 'moneda', 'nota' => 'diferencia entre ambos', 'destacar' => true],
            ['etiqueta' => 'Productos por reponer', 'valor' => $alertas->count(), 'formato' => 'entero', 'nota' => 'en su stock mínimo o por debajo'],
        ];

        $doc = $this->documento('Reporte de productos e inventario', $desde, $hasta, $indicadores, [
            [
                'nombre' => 'Reponer',
                'nota' => 'Productos en su stock mínimo o por debajo. Es la foto de ahora mismo, no depende del rango.',
                'cabeceras' => ['Producto', 'Categoría', 'Stock', 'Mínimo', 'Faltante'],
                'formatos' => [null, null, 'decimal', 'decimal', 'decimal'],
                'alineacion' => ['izq', 'izq', 'der', 'der', 'der'],
                'filas' => $alertas->map(fn ($a) => [
                    $a->nombre,
                    $a->categoria,
                    (float) $a->stock_actual,
                    (float) $a->stock_minimo,
                    (float) $a->faltante,
                ])->all(),
                'vacia' => 'Nada por reponer: ningún producto está bajo su mínimo.',
            ],
            [
                'nombre' => 'Más vendidos',
                'nota' => 'Los veinte primeros por importe. Las unidades ya descuentan lo devuelto.',
                'cabeceras' => ['#', 'Código', 'Producto', 'Categoría', 'Unidades', 'Vendido', '% del total', 'Margen estimado'],
                'formatos' => ['entero', null, null, null, 'decimal', 'moneda', 'porcentaje', 'moneda'],
                'alineacion' => ['der', 'izq', 'izq', 'izq', 'der', 'der', 'der', 'der'],
                'filas' => $ranking->map(fn ($p, $i) => [
                    $i + 1,
                    $p->codigo,
                    $p->nombre,
                    $p->categoria,
                    (float) $p->unidades_vendidas,
                    (float) $p->monto_vendido,
                    $totalVendido > 0 ? (float) $p->monto_vendido / $totalVendido : 0,
                    (float) $p->margen_estimado,
                ])->all(),
                'totales' => [null, null, 'Total de los listados', null, (float) $ranking->sum('unidades_vendidas'), (float) $ranking->sum('monto_vendido'), $totalVendido > 0 ? (float) $ranking->sum('monto_vendido') / $totalVendido : 0, (float) $ranking->sum('margen_estimado')],
                'vacia' => 'No se vendió ningún producto en el período.',
            ],
        ]);

        // Ocho columnas no caben de pie en un A4.
        $doc['orientacion'] = 'landscape';

        return $doc;
    }

    /**
     * Envoltorio común: cabecera del negocio, período y moneda.
     *
     * @param  array<int, array<string, mixed>>  $indicadores
     * @param  array<int, array<string, mixed>>  $tablas
     * @return array<string, mixed>
     */
    private function documento(string $titulo, Carbon $desde, Carbon $hasta, array $indicadores, array $tablas): array
    {
        return [
            'titulo' => $titulo,
            'negocio' => [
                'nombre' => Config::negocio(),
                'documento' => Config::get('negocio_documento'),
                'direccion' => Config::get('negocio_direccion'),
                'telefono' => Config::get('negocio_telefono'),
            ],
            'moneda' => Config::moneda(),
            'periodo' => 'Período del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'),
            'generado' => now()->format('d/m/Y H:i'),
            'orientacion' => 'portrait',
            'indicadores' => $indicadores,
            'tablas' => $tablas,
        ];
    }

    /** Nombre con el rango dentro, para que dos descargas no se pisen. */
    private function nombreFichero(string $reporte, Carbon $desde, Carbon $hasta, string $extension): string
    {
        return sprintf(
            'reporte-%s_%s_%s.%s',
            $reporte,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
            $extension
        );
    }

    // ------------------------------------------------------------------ rango

    /**
     * Por defecto, los últimos 30 días. El rango se toma completo: `hasta`
     * incluye todo ese día.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(Request $request): array
    {
        $hasta = $request->date('hasta')?->endOfDay() ?? now()->endOfDay();
        $desde = $request->date('desde')?->startOfDay() ?? $hasta->copy()->subDays(29)->startOfDay();

        // Un rango al revés no dice nada: se endereza en vez de devolver vacío.
        return $desde->gt($hasta) ? [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()] : [$desde, $hasta];
    }

    // ----------------------------------------------------------------- ventas

    /** @return array<string, float|int> */
    private function resumenVentas(Carbon $desde, Carbon $hasta): array
    {
        $ventas = DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw("SUM(estado <> 'ANULADA') AS operaciones")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', total, 0)), 0) AS vendido")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', impuesto, 0)), 0) AS impuesto")
            ->selectRaw("SUM(estado = 'ANULADA') AS anuladas")
            ->first();

        // Dos cuentas distintas, y cada una responde a una pregunta:
        //   - lo devuelto REGISTRADO en el período es lo que salió del cajón
        //     estos días, y es lo que cuadra con los arqueos;
        //   - lo devuelto DE LAS VENTAS del período es lo que hay que restarle
        //     a lo vendido para saber qué quedó de verdad. Antes el «neto» de
        //     esta pantalla usaba el primero y el ranking de productos el
        //     segundo: dos cifras con el mismo nombre que no coincidían.
        $devuelto = (float) DB::table('devoluciones')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('total');

        $devueltoDeLasVentas = (float) DB::table('devoluciones as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->sum('d.total');

        /*
         * La ganancia, con un solo criterio de fecha y sin impuesto:
         *
         *   (ventas del período − devoluciones del período, las dos sin IVA)
         *   − (costo de lo vendido en el período − costo de lo que volvió al
         *      estante en el período)
         *
         * El IVA cobrado no es del negocio. Antes: se restaba con impuesto, el
         * costo de lo devuelto se descontaba por la fecha de la VENTA (junio
         * subía cada vez que se registraba en julio una devolución de junio) y
         * la mercadería dañada que no reingresó contaba como recuperada. El
         * costo es el del día de la venta (`costo_unitario`), no el de hoy.
         */
        $base = (float) DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COALESCE(SUM(total - impuesto), 0) AS base')
            ->value('base');

        $devuelta = DB::table('devolucion_detalle as dd')
            ->join('devoluciones as dv', 'dv.id', '=', 'dd.devolucion_id')
            ->join('venta_detalle as vd', 'vd.id', '=', 'dd.venta_detalle_id')
            ->join('productos as p', 'p.id', '=', 'dd.producto_id')
            ->whereBetween('dv.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(dd.importe), 0) AS base')
            ->selectRaw('COALESCE(SUM(IF(dd.reingresa_stock = 1, dd.cantidad * COALESCE(vd.costo_unitario, p.precio_compra), 0)), 0) AS costo')
            ->first();

        $costo = (float) DB::table('venta_detalle as vd')
            ->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->join('productos as p', 'p.id', '=', 'vd.producto_id')
            ->where('v.estado', '<>', 'ANULADA')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(vd.cantidad * COALESCE(vd.costo_unitario, p.precio_compra)), 0) AS costo')
            ->value('costo');

        $operaciones = (int) $ventas->operaciones;
        $vendido = (float) $ventas->vendido;
        $efectivo = $this->efectivoACaja($desde, $hasta);

        return [
            'operaciones' => $operaciones,
            'vendido' => $vendido,
            'impuesto' => (float) $ventas->impuesto,
            'anuladas' => (int) $ventas->anuladas,
            'devuelto' => $devuelto,
            'devuelto_de_las_ventas' => $devueltoDeLasVentas,
            'neto' => round($vendido - $devueltoDeLasVentas, 2),
            'efectivo' => $efectivo,
            'ganancia' => round(($base - (float) $devuelta->base) - ($costo - (float) $devuelta->costo), 2),
            'ticket' => $operaciones > 0 ? round($vendido / $operaciones, 2) : 0.0,
        ];
    }

    /**
     * El efectivo que pasó por las cajas en el período: ventas cobradas en
     * efectivo − devoluciones pagadas del cajón + ingresos − egresos de caja.
     *
     * Es la cuenta del arqueo sin el monto inicial (ver sp_cerrar_caja y
     * SesionCaja::desgloseDelEfectivo): con turnos que empiezan y terminan
     * dentro del rango, da lo mismo que sumar «esperado − inicial» de todos.
     * El «neto» de arriba mezcla tarjeta, QR y transferencia, y no cuadra con
     * ningún cajón.
     */
    private function efectivoACaja(Carbon $desde, Carbon $hasta): float
    {
        $ventas = (float) DB::table('venta_pagos as vp')
            ->join('ventas as v', 'v.id', '=', 'vp.venta_id')
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->where('v.estado', '<>', 'ANULADA')
            ->where('mp.afecta_caja', 1)
            ->sum('vp.monto');

        $movimientos = DB::table('movimientos_caja')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw("COALESCE(SUM(IF(tipo = 'INGRESO', monto, 0)), 0) AS ingresos")
            ->selectRaw("COALESCE(SUM(IF(tipo = 'EGRESO', monto, 0)), 0) AS egresos")
            ->first();

        $devuelto = (float) DB::table('devoluciones as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->whereBetween('d.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(IFNULL(d.efectivo, ROUND(d.total * IFNULL((
                    SELECT SUM(vp.monto) FROM venta_pagos vp
                      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
                     WHERE vp.venta_id = d.venta_id AND mp.afecta_caja = 1
                 ) / NULLIF(v.total, 0), 0), 2))), 0) AS devuelto')
            ->value('devuelto');

        return round($ventas + (float) $movimientos->ingresos - (float) $movimientos->egresos - $devuelto, 2);
    }

    /**
     * Cuánto más (o menos) se vendió que en el mismo largo de período,
     * inmediatamente anterior. Sin esto, "vendiste Bs 352.95" no dice si es
     * bueno o malo. Null si el período anterior no tuvo ventas: un porcentaje
     * contra cero no significa nada.
     */
    private function variacionVendido(Carbon $desde, Carbon $hasta): ?float
    {
        $dias = (int) $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
        $hastaAnterior = $desde->copy()->subSecond();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1)->startOfDay();

        $vendidoAnterior = (float) DB::table('ventas')
            ->whereBetween('fecha', [$desdeAnterior, $hastaAnterior])
            ->where('estado', '<>', 'ANULADA')
            ->sum('total');

        if ($vendidoAnterior <= 0) {
            return null;
        }

        $vendidoActual = (float) DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '<>', 'ANULADA')
            ->sum('total');

        return round(($vendidoActual - $vendidoAnterior) / $vendidoAnterior * 100, 1);
    }

    /**
     * Serie diaria, con los días sin ventas rellenados en cero.
     *
     * `v_ventas_por_dia` es la definición oficial, pero agrupa por
     * `DATE(fecha)` y no admite rango: filtrar por `dia` después de agrupar
     * obliga a MySQL a recorrer la tabla `ventas` completa en cada consulta
     * (confirmado con EXPLAIN — ni el índice de fecha ni el de estado sirven
     * contra un `WHERE` sobre una columna ya calculada). Se repite la misma
     * fórmula filtrando ANTES de agrupar, como ya hace `masVendidos()` con
     * `v_productos_mas_vendidos` por el mismo motivo: así sí usa el índice.
     * Un test compara ambas sobre todo el histórico para que no se separen.
     */
    private function porDia(Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw('DATE(fecha)'))
            ->selectRaw('DATE(fecha) AS dia')
            ->selectRaw('COUNT(*) AS cantidad_ventas')
            ->selectRaw('SUM(total) AS monto_total')
            ->selectRaw('ROUND(AVG(total), 2) AS ticket_promedio')
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        // Sin rellenar los huecos, el gráfico uniría dos días lejanos con una
        // recta y aparentaría ventas que no existieron.
        for ($dia = $desde->copy()->startOfDay(); $dia->lte($hasta); $dia->addDay()) {
            $clave = $dia->toDateString();
            $fila = $filas->get($clave);

            $serie[] = [
                'dia' => $clave,
                'etiqueta' => $dia->format('d/m'),
                'ventas' => (int) ($fila->cantidad_ventas ?? 0),
                'monto' => (float) ($fila->monto_total ?? 0),
                'ticket' => (float) ($fila->ticket_promedio ?? 0),
            ];
        }

        return $serie;
    }

    /**
     * Mismo motivo que `porDia()`: `v_ventas_por_metodo_pago` agrupa por
     * `DATE(fecha)` y filtrarla por rango obliga a un recorrido completo.
     * Se repite la fórmula contra las tablas base, filtrando antes de
     * agrupar, y de paso se ahorra la doble agregación (día→método y luego
     * método) que hacía falta al consultar la vista.
     */
    private function porMetodoPago(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('venta_pagos as vp')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'vp.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->groupBy('mp.nombre')
            ->selectRaw('mp.nombre AS metodo_pago')
            ->selectRaw('COUNT(DISTINCT v.id) AS ventas')
            ->selectRaw('SUM(vp.monto) AS monto')
            ->orderByDesc('monto')
            ->get();
    }

    /** No hay vista para esto: el desglose por vendedor es propio del reporte. */
    private function porCajero(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('ventas as v')
            ->join('usuarios as u', 'u.id', '=', 'v.usuario_id')
            ->join('empleados as e', 'e.id', '=', 'u.empleado_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->where('v.estado', '<>', 'ANULADA')
            ->groupBy('u.id', 'u.usuario', 'e.nombre_completo')
            ->selectRaw('u.usuario, e.nombre_completo AS empleado')
            ->selectRaw('COUNT(*) AS ventas, SUM(v.total) AS monto, ROUND(AVG(v.total), 2) AS ticket')
            ->orderByDesc('monto')
            ->get();
    }

    // -------------------------------------------------------------- productos

    /**
     * Ranking del período. Es `v_productos_mas_vendidos` con un filtro de
     * fechas: la vista agrega todo el histórico y no admite rango, así que la
     * consulta se repite aquí con **las mismas fórmulas**. Una prueba compara
     * ambas sin filtro para que no se separen con el tiempo.
     */
    /** Lo vendido en el período por todos los productos, con el mismo neto que el ranking. */
    private function totalVendidoNeto(Carbon $desde, Carbon $hasta): float
    {
        return (float) DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(ROUND(d.importe * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1)'
                .' * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2)), 0) AS total')
            ->value('total');
    }

    private function masVendidos(Carbon $desde, Carbon $hasta): Collection
    {
        // Neto del descuento de la venta —repartido entre sus líneas: ahí vive
        // el descuento del mostrador— y de lo devuelto, igual que la vista
        // `v_productos_mas_vendidos`. El margen, con el costo del día de la venta.
        $neto = 'ROUND(d.importe * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1)'
            .' * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2)';
        $unidades = '(d.cantidad - d.cantidad_devuelta)';
        $costo = 'COALESCE(d.costo_unitario, p.precio_compra)';

        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('productos as p', 'p.id', '=', 'd.producto_id')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->groupBy('p.id', 'p.codigo', 'p.nombre', 'c.nombre')
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria')
            ->selectRaw("SUM({$unidades}) AS unidades_vendidas")
            ->selectRaw("SUM({$neto}) AS monto_vendido")
            ->selectRaw("SUM({$neto} - ROUND({$unidades} * {$costo}, 2)) AS margen_estimado")
            // Columna añadida, fuera de la vista: deja ver cuánto se devolvió
            // de un producto sin tener que abrir su ficha.
            ->selectRaw('SUM(d.cantidad_devuelta) AS unidades_devueltas')
            ->orderByDesc('monto_vendido')
            ->limit(20)
            ->get();
    }

    /** @return array<string, float|int> */
    private function valorInventario(): array
    {
        // El precio de venta sin el impuesto: con los precios que ya lo
        // incluyen, «lo que se cobraría por todo» traía el IVA adentro y el
        // margen salía casi al triple. El IVA no es del negocio.
        $tasa = number_format(Config::tasaImpuesto(), 4, '.', '');
        $sinImpuesto = Config::preciosIncluyenImpuesto()
            ? "IF(afecto_impuesto = 1, precio_venta - ROUND(precio_venta * {$tasa} / (1 + {$tasa}), 2), precio_venta)"
            : 'precio_venta';

        // Sobre todo lo que hay en estante, activo o no: dar de baja un
        // producto con stock no hace desaparecer lo que costó.
        $totales = DB::table('productos')
            ->selectRaw('COALESCE(SUM(activo = 1), 0) AS productos')
            ->selectRaw('COALESCE(SUM(activo = 0 AND stock_actual > 0), 0) AS inactivos_con_stock')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS costo')
            ->selectRaw("COALESCE(SUM(stock_actual * {$sinImpuesto}), 0) AS venta")
            ->first();

        return [
            'productos' => (int) $totales->productos,
            'inactivos_con_stock' => (int) $totales->inactivos_con_stock,
            'costo' => (float) $totales->costo,
            'venta' => (float) $totales->venta,
            'margen' => round((float) $totales->venta - (float) $totales->costo, 2),
            'moneda' => Config::moneda(),
        ];
    }
}
