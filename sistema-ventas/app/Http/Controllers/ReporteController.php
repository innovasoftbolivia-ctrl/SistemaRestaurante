<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\Hojas;
use App\Support\Config;
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

    public function ventasExcel(Request $request): StreamedResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return (new Hojas($this->documentoVentas($desde, $hasta)))
            ->descargar($this->nombreFichero('ventas', $desde, $hasta, 'xlsx'));
    }

    public function productosExcel(Request $request): StreamedResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return (new Hojas($this->documentoProductos($desde, $hasta)))
            ->descargar($this->nombreFichero('productos', $desde, $hasta, 'xlsx'));
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
            ['etiqueta' => 'Devuelto', 'valor' => $resumen['devuelto'], 'formato' => 'moneda', 'nota' => 'a clientes, por devoluciones'],
            ['etiqueta' => 'Neto', 'valor' => $resumen['neto'], 'formato' => 'moneda', 'nota' => 'vendido menos devuelto', 'destacar' => true],
            ['etiqueta' => 'Ticket promedio', 'valor' => $resumen['ticket'], 'formato' => 'moneda', 'nota' => 'vendido entre operaciones'],
            ['etiqueta' => 'Ventas anuladas', 'valor' => $resumen['anuladas'], 'formato' => 'entero', 'nota' => 'revirtieron su stock'],
        ];

        if ($tasa > 0) {
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

        $totalVendido = (float) $ranking->sum('monto_vendido');

        $indicadores = [
            ['etiqueta' => 'Productos activos', 'valor' => $inventario['productos'], 'formato' => 'entero', 'nota' => 'en catálogo'],
            ['etiqueta' => 'Inventario a costo', 'valor' => $inventario['costo'], 'formato' => 'moneda', 'nota' => 'lo que costó lo que hay en estante'],
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
                'totales' => [null, null, 'Total', null, (float) $ranking->sum('unidades_vendidas'), $totalVendido, $totalVendido > 0 ? 1.0 : 0, (float) $ranking->sum('margen_estimado')],
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

        $devuelto = (float) DB::table('devoluciones')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('total');

        // Costo de lo vendido, para dar una ganancia y no solo un total cobrado.
        // Usa el `precio_compra` DE HOY: es una aproximación, no el costo exacto
        // que tenía el producto el día que se vendió (mismo criterio que ya usa
        // el "margen estimado" del reporte de productos). `cantidad -
        // cantidad_devuelta` y no `cantidad`: lo devuelto ya se restó del
        // ingreso (`$devuelto` abajo), así que su costo tampoco puede seguir
        // contando — si no, una venta devuelta del todo mostraría "pérdida"
        // por el costo de mercadería que en realidad volvió intacta al estante.
        $costo = (float) DB::table('venta_detalle as vd')
            ->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->join('productos as p', 'p.id', '=', 'vd.producto_id')
            ->where('v.estado', '<>', 'ANULADA')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM((vd.cantidad - vd.cantidad_devuelta) * p.precio_compra), 0) AS costo')
            ->value('costo');

        $operaciones = (int) $ventas->operaciones;
        $vendido = (float) $ventas->vendido;

        return [
            'operaciones' => $operaciones,
            'vendido' => $vendido,
            'impuesto' => (float) $ventas->impuesto,
            'anuladas' => (int) $ventas->anuladas,
            'devuelto' => $devuelto,
            'neto' => round($vendido - $devuelto, 2),
            'ganancia' => round($vendido - $devuelto - $costo, 2),
            'ticket' => $operaciones > 0 ? round($vendido / $operaciones, 2) : 0.0,
        ];
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
    private function masVendidos(Carbon $desde, Carbon $hasta): Collection
    {
        // Neto de devoluciones: el importe de cada línea se prorratea por la
        // fracción que el cliente se quedó, igual que hace la vista.
        $neto = 'ROUND(d.importe * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2)';
        $unidades = '(d.cantidad - d.cantidad_devuelta)';

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
            ->selectRaw("SUM({$neto} - ROUND({$unidades} * p.precio_compra, 2)) AS margen_estimado")
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
        $totales = DB::table('productos')
            ->where('activo', 1)
            ->selectRaw('COUNT(*) AS productos')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS costo')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_venta), 0) AS venta')
            ->first();

        return [
            'productos' => (int) $totales->productos,
            'costo' => (float) $totales->costo,
            'venta' => (float) $totales->venta,
            'margen' => round((float) $totales->venta - (float) $totales->costo, 2),
            'moneda' => Config::moneda(),
        ];
    }
}
