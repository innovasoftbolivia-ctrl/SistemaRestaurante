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
 * (`v_ventas_por_dia`, `v_ventas_por_metodo_pago`): son la
 * definición oficial de cada cifra y no se reescriben aquí.
 *
 * Todo va por JORNADA, no por día de calendario: el local cierra pasada la
 * medianoche y la venta de la 01:30 es de la noche anterior, igual que el
 * número de su pedido (`Config::jornadaDe`). El período elige jornadas, y se
 * filtra con `Config::momentosDeJornadas` y se agrupa con `Config::jornadaSql`.
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
            'title' => 'Reporte del menú',
            'desde' => $desde,
            'hasta' => $hasta,
            'masVendidos' => $this->masVendidos($desde, $hasta),
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
            ['etiqueta' => self::rotuloVendidoConImpuesto(), 'valor' => $resumen['vendido'], 'formato' => 'moneda', 'nota' => 'suma de los totales cobrados'.($tasa > 0 ? ', con el impuesto' : '')],
            ['etiqueta' => 'Efectivo en cajas', 'valor' => $resumen['efectivo'], 'formato' => 'moneda', 'nota' => 'ventas cobradas en efectivo, más ingresos y menos egresos: cuadra con los arqueos, sin el monto inicial'],
            ['etiqueta' => 'Ticket promedio', 'valor' => $resumen['ticket'], 'formato' => 'moneda', 'nota' => 'vendido entre operaciones'],
            ['etiqueta' => 'Ventas anuladas', 'valor' => $resumen['anuladas'], 'formato' => 'entero', 'nota' => 'no cuentan en lo vendido'],
        ];

        if ($tasa > 0 && Config::facturacionVisible()) {
            $indicadores[] = ['etiqueta' => 'Impuesto', 'valor' => $resumen['impuesto'], 'formato' => 'moneda', 'nota' => 'incluido en lo vendido'];
        }

        return $this->documento('Reporte de ventas', $desde, $hasta, $indicadores, [
            [
                'nombre' => 'Ventas por jornada',
                'nota' => 'Solo las jornadas con movimiento. '.self::notaJornada(),
                'cabeceras' => ['Jornada', 'Ventas', 'Ticket promedio', self::rotuloVendidoConImpuesto()],
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
     * Qué lleva el reporte del menú: qué se vende y cuánto se vendió.
     *
     * @return array<string, mixed>
     */
    private function documentoProductos(Carbon $desde, Carbon $hasta): array
    {
        $ranking = $this->masVendidos($desde, $hasta)->values();

        // El porcentaje, contra todo lo vendido en el período y no contra los
        // veinte de la tabla: con cientos de ítems, esos veinte sumaban
        // siempre 100 %.
        $totalVendido = $this->totalVendidoNeto($desde, $hasta);

        $indicadores = [
            ['etiqueta' => 'Ítems en el menú', 'valor' => Producto::where('activo', 1)->count(), 'formato' => 'entero', 'nota' => 'disponibles hoy'],
            ['etiqueta' => 'Ítems vendidos', 'valor' => $ranking->count(), 'formato' => 'entero', 'nota' => 'distintos, entre los veinte primeros del período'],
            ['etiqueta' => self::rotuloVendidoSinImpuesto(), 'valor' => $totalVendido, 'formato' => 'moneda', 'nota' => 'neto de descuentos'.(Config::tasaImpuesto() > 0 ? ', antes del impuesto: no es el «Vendido (con impuesto)» del reporte de ventas' : ''), 'destacar' => true],
        ];

        $doc = $this->documento('Reporte del menú', $desde, $hasta, $indicadores, [
            [
                'nombre' => 'Más vendidos',
                'nota' => 'Los veinte primeros por importe, sin contar las ventas anuladas.',
                'cabeceras' => ['#', 'Código', 'Ítem del menú', 'Categoría', 'Unidades', self::rotuloVendidoSinImpuesto(), '% del total'],
                'formatos' => ['entero', null, null, null, 'decimal', 'moneda', 'porcentaje'],
                'alineacion' => ['der', 'izq', 'izq', 'izq', 'der', 'der', 'der'],
                'filas' => $ranking->map(fn ($p, $i) => [
                    $i + 1,
                    $p->codigo,
                    $p->nombre,
                    $p->categoria,
                    (float) $p->unidades_vendidas,
                    (float) $p->monto_vendido,
                    $totalVendido > 0 ? (float) $p->monto_vendido / $totalVendido : 0,
                ])->all(),
                'totales' => [null, null, 'Total de los listados', null, (float) $ranking->sum('unidades_vendidas'), (float) $ranking->sum('monto_vendido'), $totalVendido > 0 ? (float) $ranking->sum('monto_vendido') / $totalVendido : 0],
                'vacia' => 'No se vendió nada del menú en el período.',
            ],
        ]);

        // Siete columnas no caben de pie en un A4.
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

    /**
     * «Vendido» dice dos cosas distintas en los dos reportes: en el de ventas
     * es lo que pagó el cliente, con el impuesto; en el ranking del menú, la
     * base neta de descuentos, sin él. Con tasa cero coinciden y basta la
     * palabra; con impuesto, el rótulo dice cuál es cuál.
     */
    public static function rotuloVendidoConImpuesto(): string
    {
        return Config::tasaImpuesto() > 0 ? 'Vendido (con impuesto)' : 'Vendido';
    }

    public static function rotuloVendidoSinImpuesto(): string
    {
        return Config::tasaImpuesto() > 0 ? 'Vendido sin impuesto' : 'Vendido';
    }

    /** Qué es una jornada, para quien lee el reporte: el día no termina a medianoche. */
    public static function notaJornada(): string
    {
        return sprintf(
            'Cada jornada va de las %1$02d:00 a las %1$02d:00 del día siguiente: lo vendido pasada la medianoche cuenta para la noche anterior.',
            Config::horaCorteJornada(),
        );
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
     * Por defecto, las últimas 30 jornadas. Las fechas son jornadas: `hasta`
     * incluye todo lo vendido en esa jornada, también lo de pasada la
     * medianoche. Y la de hoy es la jornada en curso: a la 01:30 todavía es
     * la de ayer.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(Request $request): array
    {
        $hasta = $this->fecha($request, 'hasta')?->endOfDay() ?? Carbon::parse(Config::jornadaActual())->endOfDay();
        $desde = $this->fecha($request, 'desde')?->startOfDay() ?? $hasta->copy()->subDays(29)->startOfDay();

        // Un rango al revés no dice nada: se endereza en vez de devolver vacío.
        if ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
        }

        // Con tope: un año mal tecleado (0202) armaba millones de días en
        // memoria y dejaba el proceso colgado hasta el límite de tiempo.
        $minimo = $hasta->copy()->subDays(self::MAX_DIAS_RANGO - 1)->startOfDay();

        return [$desde->lt($minimo) ? $minimo : $desde, $hasta];
    }

    /** Días que abarca como mucho un reporte: diez años, de sobra para el histórico. */
    private const MAX_DIAS_RANGO = 3653;

    /** Una fecha del filtro, o null si falta o no se entiende (en vez de un 500). */
    private function fecha(Request $request, string $campo): ?Carbon
    {
        $valor = $request->query($campo);

        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $valor) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ----------------------------------------------------------------- ventas

    /** @return array<string, float|int> */
    private function resumenVentas(Carbon $desde, Carbon $hasta): array
    {
        $ventas = DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->selectRaw("SUM(estado <> 'ANULADA') AS operaciones")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', total, 0)), 0) AS vendido")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', impuesto, 0)), 0) AS impuesto")
            ->selectRaw("SUM(estado = 'ANULADA') AS anuladas")
            ->first();

        $operaciones = (int) $ventas->operaciones;
        $vendido = (float) $ventas->vendido;
        $efectivo = $this->efectivoACaja($desde, $hasta);

        return [
            'operaciones' => $operaciones,
            'vendido' => $vendido,
            'impuesto' => (float) $ventas->impuesto,
            'anuladas' => (int) $ventas->anuladas,
            'efectivo' => $efectivo,
            'ticket' => $operaciones > 0 ? round($vendido / $operaciones, 2) : 0.0,
        ];
    }

    /**
     * El efectivo que pasó por las cajas en el período: ventas cobradas en
     * efectivo + ingresos − egresos de caja.
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
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('v.estado', '<>', 'ANULADA')
            ->where('mp.afecta_caja', 1)
            ->sum('vp.monto');

        $movimientos = DB::table('movimientos_caja')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->selectRaw("COALESCE(SUM(IF(tipo = 'INGRESO', monto, 0)), 0) AS ingresos")
            ->selectRaw("COALESCE(SUM(IF(tipo = 'EGRESO', monto, 0)), 0) AS egresos")
            ->first();

        return round($ventas + (float) $movimientos->ingresos - (float) $movimientos->egresos, 2);
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
        $hastaAnterior = $desde->copy()->subDay();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1);

        $vendidoAnterior = (float) DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desdeAnterior, $hastaAnterior))
            ->where('estado', '<>', 'ANULADA')
            ->sum('total');

        if ($vendidoAnterior <= 0) {
            return null;
        }

        $vendidoActual = (float) DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('estado', '<>', 'ANULADA')
            ->sum('total');

        return round(($vendidoActual - $vendidoAnterior) / $vendidoAnterior * 100, 1);
    }

    /**
     * Serie por jornada, con las jornadas sin ventas rellenadas en cero.
     *
     * `v_ventas_por_dia` es la definición oficial, pero agrupa por
     * jornada y no admite rango: filtrar por `dia` después de agrupar
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
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw(Config::jornadaSql('fecha')))
            ->selectRaw(Config::jornadaSql('fecha').' AS dia')
            ->selectRaw('COUNT(*) AS cantidad_ventas')
            ->selectRaw('SUM(total) AS monto_total')
            ->selectRaw('ROUND(AVG(total), 2) AS ticket_promedio')
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        // Sin rellenar los huecos, el gráfico uniría dos jornadas lejanas con
        // una recta y aparentaría ventas que no existieron.
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
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
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
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('v.estado', '<>', 'ANULADA')
            ->groupBy('u.id', 'u.usuario', 'e.nombre_completo')
            ->selectRaw('u.usuario, e.nombre_completo AS empleado')
            ->selectRaw('COUNT(*) AS ventas, SUM(v.total) AS monto, ROUND(AVG(v.total), 2) AS ticket')
            ->orderByDesc('monto')
            ->get();
    }

    // ------------------------------------------------------------ el menú

    /**
     * Ranking del período. Es `v_productos_mas_vendidos` con un filtro de
     * fechas: la vista agrega todo el histórico y no admite rango, así que la
     * consulta se repite aquí con **las mismas fórmulas**. Una prueba compara
     * ambas sin filtro para que no se separen con el tiempo.
     */
    /** Lo vendido en el período por todo el menú, con la misma cuenta que el ranking. */
    private function totalVendidoNeto(Carbon $desde, Carbon $hasta): float
    {
        return (float) DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->selectRaw('COALESCE(SUM(ROUND(d.importe * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2)), 0) AS total')
            ->value('total');
    }

    private function masVendidos(Carbon $desde, Carbon $hasta): Collection
    {
        // Neto del descuento de la venta —repartido entre sus líneas: ahí vive
        // el descuento del mostrador—, igual que la vista
        // `v_productos_mas_vendidos`.
        $neto = 'ROUND(d.importe * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2)';
        $unidades = 'd.cantidad';

        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('productos as p', 'p.id', '=', 'd.producto_id')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->groupBy('p.id', 'p.codigo', 'p.nombre', 'c.nombre')
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria')
            ->selectRaw("SUM({$unidades}) AS unidades_vendidas")
            ->selectRaw("SUM({$neto}) AS monto_vendido")
            ->orderByDesc('monto_vendido')
            ->limit(20)
            ->get();
    }
}
