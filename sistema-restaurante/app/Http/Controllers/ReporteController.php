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
            'porHora' => $this->porHora($desde, $hasta),
            'porDiaSemana' => $this->porDiaSemana($desde, $hasta),
            'porTipo' => $this->porTipo($desde, $hasta),
            'porMetodo' => $this->porMetodoPago($desde, $hasta),
            'porCajero' => $this->porCajero($desde, $hasta),
            'anuladas' => $this->anuladas($desde, $hasta),
            'cuadres' => $this->cuadresDeCaja($desde, $hasta),
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
            'porCategoria' => $this->porCategoria($desde, $hasta),
            'sinVentas' => $this->sinVentas($desde, $hasta),
            'totalVendido' => $this->totalVendidoNeto($desde, $hasta),
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
        $horas = collect($this->porHora($desde, $hasta));
        $semana = collect($this->porDiaSemana($desde, $hasta));
        $tipos = $this->porTipo($desde, $hasta);
        $anuladas = $this->anuladas($desde, $hasta);
        $cuadres = $this->cuadresDeCaja($desde, $hasta);

        $totalMetodos = (float) $metodos->sum('monto');
        $totalCajeros = (float) $cajeros->sum('monto');

        $indicadores = [
            ['etiqueta' => 'Operaciones', 'valor' => $resumen['operaciones'], 'formato' => 'entero', 'nota' => 'ventas cobradas, sin contar anuladas'],
            ['etiqueta' => self::rotuloVendidoConImpuesto(), 'valor' => $resumen['vendido'], 'formato' => 'moneda', 'nota' => 'suma de los totales cobrados'.($tasa > 0 ? ', con el impuesto' : '')],
            ['etiqueta' => 'Efectivo en cajas', 'valor' => $resumen['efectivo'], 'formato' => 'moneda', 'nota' => 'ventas cobradas en efectivo, más ingresos y menos egresos: cuadra con los arqueos de los turnos que abren y cierran dentro del período, sin su monto inicial'],
            ['etiqueta' => 'Ticket promedio', 'valor' => $resumen['ticket'], 'formato' => 'moneda', 'nota' => 'vendido entre operaciones'],
            ['etiqueta' => 'Descuentos otorgados', 'valor' => $resumen['descuentos'], 'formato' => 'moneda', 'nota' => 'en '.$resumen['con_descuento'].' venta(s): lo que se dejó de cobrar'],
            ['etiqueta' => 'Ventas anuladas', 'valor' => $resumen['anuladas'], 'formato' => 'entero', 'nota' => 'por '.Config::importe($resumen['monto_anulado']).'; no cuentan en lo vendido'],
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
            [
                'nombre' => 'Por hora del día',
                'nota' => 'Cuándo se vende: sirve para decidir cuánta gente hace falta en cada momento.',
                'cabeceras' => ['Hora', 'Ventas', self::rotuloVendidoConImpuesto()],
                'formatos' => [null, 'entero', 'moneda'],
                'alineacion' => ['izq', 'der', 'der'],
                'filas' => $horas->map(fn ($h) => [$h['tramo'], $h['ventas'], $h['monto']])->all(),
                'vacia' => 'No hubo ventas en el período.',
            ],
            [
                'nombre' => 'Por día de la semana',
                'nota' => 'El promedio es por jornada: en un mes no hay la misma cantidad de cada día.',
                'cabeceras' => ['Día', 'Jornadas', 'Ventas', self::rotuloVendidoConImpuesto(), 'Promedio por jornada'],
                'formatos' => [null, 'entero', 'entero', 'moneda', 'moneda'],
                'alineacion' => ['izq', 'der', 'der', 'der', 'der'],
                'filas' => $semana->map(fn ($d) => [$d['dia'], $d['jornadas'], $d['ventas'], $d['monto'], $d['promedio']])->all(),
                'vacia' => 'No hubo ventas en el período.',
            ],
            [
                'nombre' => 'Comer aquí o para llevar',
                'cabeceras' => ['Pedido', 'Ventas', 'Ticket', self::rotuloVendidoConImpuesto()],
                'formatos' => [null, 'entero', 'moneda', 'moneda'],
                'alineacion' => ['izq', 'der', 'der', 'der'],
                'filas' => $tipos->map(fn ($t) => [$t->tipo, $t->ventas, $t->ticket, $t->monto])->all(),
                'vacia' => 'No hubo ventas en el período.',
            ],
            [
                'nombre' => 'Descuentos por cajero',
                'nota' => 'Lo que cada uno dejó de cobrar. Un descuento alto que se repite es lo primero que conviene revisar.',
                'cabeceras' => ['Cajero', 'Usuario', 'Ventas con descuento', 'Descontado'],
                'formatos' => [null, null, 'entero', 'moneda'],
                'alineacion' => ['izq', 'izq', 'der', 'der'],
                'filas' => $cajeros->filter(fn ($f) => (float) $f->descontado > 0)->sortByDesc('descontado')
                    ->map(fn ($f) => [$f->empleado, $f->usuario, (int) $f->con_descuento, (float) $f->descontado])->values()->all(),
                'vacia' => 'No se hicieron descuentos en el período.',
            ],
            [
                'nombre' => 'Ventas anuladas',
                'nota' => 'Quién cobró, quién anuló y por qué.',
                'cabeceras' => ['Fecha', 'Documento', 'Cobró', 'Anuló', 'Motivo', 'Total'],
                'formatos' => [null, null, null, null, null, 'moneda'],
                'alineacion' => ['izq', 'izq', 'izq', 'izq', 'izq', 'der'],
                'filas' => $anuladas->map(fn ($a) => [
                    Carbon::parse($a->fecha)->format('d/m/Y H:i'), $a->numero_completo ?? '#'.$a->id, $a->cobro, $a->anulo, $a->motivo_anulacion, (float) $a->total,
                ])->all(),
                'totales' => ['Total', null, null, null, null, (float) $anuladas->sum('total')],
                'vacia' => 'No se anuló ninguna venta en el período.',
            ],
            [
                'nombre' => 'Cuadre de caja',
                'nota' => 'Turnos cerrados en el período. Diferencia = contado − esperado: negativa es faltante, positiva sobrante. Retirado = contado − lo que quedó en el cajón para el turno siguiente.',
                'cabeceras' => ['Cierre', 'Caja', 'Cajero', 'Cerró', 'Esperado', 'Contado', 'Diferencia', 'Retirado', 'Explicación'],
                'formatos' => [null, null, null, null, 'moneda', 'moneda', 'moneda', 'moneda', null],
                'alineacion' => ['izq', 'izq', 'izq', 'izq', 'der', 'der', 'der', 'der', 'izq'],
                'filas' => $cuadres->map(fn ($c) => [
                    Carbon::parse($c->fecha_cierre)->format('d/m/Y H:i'), $c->caja, $c->usuario, $c->cerro, (float) $c->monto_esperado, (float) $c->monto_declarado, (float) $c->diferencia, $c->retirado === null ? null : (float) $c->retirado, $c->observacion_cierre,
                ])->all(),
                'totales' => ['Total', null, null, null, (float) $cuadres->sum('monto_esperado'), (float) $cuadres->sum('monto_declarado'), (float) $cuadres->sum('diferencia'), (float) $cuadres->sum('retirado'), null],
                'vacia' => 'No se cerró ningún turno en el período.',
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
        $categorias = $this->porCategoria($desde, $hasta);
        $sinVentas = $this->sinVentas($desde, $hasta);
        $totalVendido = $this->totalVendidoNeto($desde, $hasta);
        $ganancias = self::ganancias($ranking);

        $indicadores = [
            ['etiqueta' => 'Ítems en el menú', 'valor' => Producto::where('activo', 1)->count(), 'formato' => 'entero', 'nota' => 'disponibles hoy'],
            ['etiqueta' => 'Ítems vendidos', 'valor' => $ranking->count(), 'formato' => 'entero', 'nota' => 'distintos, al menos una vez en el período'],
            ['etiqueta' => 'Sin ninguna venta', 'valor' => $sinVentas->count(), 'formato' => 'entero', 'nota' => 'en la carta, pero nadie los pidió'],
            ['etiqueta' => self::rotuloVendidoSinImpuesto(), 'valor' => $totalVendido, 'formato' => 'moneda', 'nota' => 'neto de descuentos'.(Config::tasaImpuesto() > 0 ? ', antes del impuesto: no es el «Vendido (con impuesto)» del reporte de ventas' : ''), 'destacar' => true],
        ];
        if ($ganancias->isNotEmpty()) {
            $indicadores[] = ['etiqueta' => 'Ganancia', 'valor' => $ganancias->sum('ganancia'), 'formato' => 'moneda', 'nota' => 'de lo que tiene costo: lo vendido menos lo que costó'];
        }

        $doc = $this->documento('Reporte del menú', $desde, $hasta, $indicadores, [
            [
                'nombre' => 'Ranking',
                'nota' => 'Todo lo vendido, por importe, sin contar las ventas anuladas.',
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
                'totales' => [null, null, 'Total', null, (float) $ranking->sum('unidades_vendidas'), (float) $ranking->sum('monto_vendido'), $totalVendido > 0 ? (float) $ranking->sum('monto_vendido') / $totalVendido : 0],
                'vacia' => 'No se vendió nada del menú en el período.',
            ],
            ...($ganancias->isEmpty() ? [] : [[
                'nombre' => 'Ganancia',
                'nota' => 'Solo lo que tiene costo (lo que se compra al proveedor): lo vendido menos lo que costó al momento de venderlo.',
                'cabeceras' => ['Código', 'Ítem del menú', 'Categoría', self::rotuloVendidoSinImpuesto(), 'Costo', 'Ganancia', 'Margen'],
                'formatos' => [null, null, null, 'moneda', 'moneda', 'moneda', 'porcentaje'],
                'alineacion' => ['izq', 'izq', 'izq', 'der', 'der', 'der', 'der'],
                'filas' => $ganancias->map(fn ($g) => [$g->codigo, $g->nombre, $g->categoria, $g->vendido, $g->costo, $g->ganancia, $g->margen])->all(),
                'totales' => [null, 'Total', null, $ganancias->sum('vendido'), $ganancias->sum('costo'), $ganancias->sum('ganancia'), $ganancias->sum('vendido') > 0 ? $ganancias->sum('ganancia') / $ganancias->sum('vendido') : 0],
                'vacia' => 'Nada con costo se vendió en el período.',
            ]]),
            [
                'nombre' => 'Por categoría',
                'cabeceras' => ['Categoría', 'Unidades', self::rotuloVendidoSinImpuesto(), '% del total'],
                'formatos' => [null, 'decimal', 'moneda', 'porcentaje'],
                'alineacion' => ['izq', 'der', 'der', 'der'],
                'filas' => $categorias->map(fn ($c) => [
                    $c->categoria, (float) $c->unidades, (float) $c->monto, $totalVendido > 0 ? (float) $c->monto / $totalVendido : 0,
                ])->all(),
                'vacia' => 'No se vendió nada del menú en el período.',
            ],
            [
                'nombre' => 'Sin ninguna venta',
                'nota' => 'Activos en la carta y sin una sola venta en el período: candidatos a revisar o a sacar.',
                'cabeceras' => ['Código', 'Ítem del menú', 'Categoría'],
                'formatos' => [null, null, null],
                'alineacion' => ['izq', 'izq', 'izq'],
                'filas' => $sinVentas->map(fn ($p) => [$p->codigo, $p->nombre, $p->categoria])->all(),
                'vacia' => 'Todo el menú tuvo al menos una venta.',
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
        $ventas = DB::table('ventas as v')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->selectRaw("SUM(v.estado <> 'ANULADA') AS operaciones")
            ->selectRaw("COALESCE(SUM(IF(v.estado <> 'ANULADA', v.total, 0)), 0) AS vendido")
            ->selectRaw("COALESCE(SUM(IF(v.estado <> 'ANULADA', v.impuesto, 0)), 0) AS impuesto")
            ->selectRaw("SUM(v.estado = 'ANULADA') AS anuladas")
            ->selectRaw("COALESCE(SUM(IF(v.estado = 'ANULADA', v.total, 0)), 0) AS monto_anulado")
            ->selectRaw("COALESCE(SUM(IF(v.estado <> 'ANULADA', ".self::DESCUENTO_VISIBLE.', 0)), 0) AS descuentos')
            ->selectRaw("SUM(v.estado <> 'ANULADA' AND ".self::DESCUENTO_VISIBLE.' > 0) AS con_descuento')
            ->first();

        $operaciones = (int) $ventas->operaciones;
        $vendido = (float) $ventas->vendido;
        $efectivo = $this->efectivoACaja($desde, $hasta);

        return [
            'operaciones' => $operaciones,
            'vendido' => $vendido,
            'impuesto' => (float) $ventas->impuesto,
            'anuladas' => (int) $ventas->anuladas,
            'monto_anulado' => (float) $ventas->monto_anulado,
            'descuentos' => (float) $ventas->descuentos,
            'con_descuento' => (int) $ventas->con_descuento,
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

        [$inicioActual, $finActual] = Config::momentosDeJornadas($desde, $hasta);
        [$inicioAnterior, $finAnterior] = Config::momentosDeJornadas($desdeAnterior, $hastaAnterior);

        // Si el período llega hasta hoy (o más allá), lo que falta todavía no
        // se vendió: se compara hasta AHORA contra el anterior hasta el mismo
        // punto. Sin esto, «Hoy» a mediodía contra ayer completo, o del 1 al 30
        // consultado el 22, daban caídas que no eran.
        if (now()->lt($finActual)) {
            if (now()->lt($inicioActual)) {
                return null;
            }
            $finActual = now()->toDateTimeString();
            $finAnterior = min(Carbon::parse($finAnterior), now()->subDays($dias))->toDateTimeString();
        }

        $vendidoAnterior = (float) DB::table('ventas')
            ->whereBetween('fecha', [$inicioAnterior, $finAnterior])
            ->where('estado', '<>', 'ANULADA')
            ->sum('total');

        if ($vendidoAnterior <= 0) {
            return null;
        }

        $vendidoActual = (float) DB::table('ventas')
            ->whereBetween('fecha', [$inicioActual, $finActual])
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
            ->selectRaw('SUM('.self::DESCUENTO_VISIBLE.' > 0) AS con_descuento')
            ->selectRaw('COALESCE(SUM('.self::DESCUENTO_VISIBLE.'), 0) AS descontado')
            ->orderByDesc('monto')
            ->get();
    }

    /**
     * El descuento como lo tecleó el cajero y lo vio el cliente: con el
     * impuesto incluido, sobre el precio final; si no, sobre la base. La
     * misma cuenta que `Venta::descuento_visible`.
     */
    private const DESCUENTO_VISIBLE = 'IF(v.impuesto_incluido = 1, COALESCE(v.descuento_precio_final, v.descuento), '
        // Con el impuesto sumado aparte, el descuento sobre la base también
        // baja el impuesto: lo que se dejó de cobrar es el descuento con su
        // impuesto (tasa efectiva de la venta = impuesto / base descontada).
        .'ROUND(v.descuento * IF(v.subtotal - v.descuento > 0, 1 + v.impuesto / (v.subtotal - v.descuento), 1), 2))';

    // ------------------------------------------------- cuándo y cómo se vende

    /**
     * Lo vendido por hora del día, en el orden de la jornada (de la hora de
     * corte en adelante): dice cuándo hace falta más gente en el mostrador y
     * en la cocina. Solo las horas desde la primera hasta la última con
     * ventas: veinticuatro barras con la madrugada en cero no dicen nada.
     *
     * @return array<int, array{hora: int, etiqueta: string, tramo: string, ventas: int, monto: float}>
     */
    private function porHora(Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw('HOUR(fecha)'))
            ->selectRaw('HOUR(fecha) AS hora, COUNT(*) AS ventas, SUM(total) AS monto')
            ->get()
            ->keyBy(fn ($f) => (int) $f->hora);

        if ($filas->isEmpty()) {
            return [];
        }

        $corte = Config::horaCorteJornada();
        $orden = array_map(fn ($i) => ($corte + $i) % 24, range(0, 23));
        $conVentas = array_values(array_filter($orden, fn ($h) => $filas->has($h)));
        $tramo = array_slice($orden, array_search($conVentas[0], $orden, true), array_search(end($conVentas), $orden, true) - array_search($conVentas[0], $orden, true) + 1);

        return array_map(fn (int $h) => [
            'hora' => $h,
            // Corta, para que las barras no se pisen: «13h» y no «13:00».
            'etiqueta' => $h.'h',
            'tramo' => sprintf('%02d:00 a %02d:59', $h, $h),
            'ventas' => (int) ($filas[$h]->ventas ?? 0),
            'monto' => (float) ($filas[$h]->monto ?? 0),
        ], $tramo);
    }

    /**
     * Lo vendido por día de la semana, en PROMEDIO por jornada: en un mes hay
     * cinco sábados y cuatro martes, y sumarlos sin más haría ganar al que
     * más veces cae. Dice qué días conviene reforzar y cuáles no rinden.
     *
     * @return array<int, array{dia: string, jornadas: int, ventas: int, monto: float, promedio: float}>
     */
    private function porDiaSemana(Carbon $desde, Carbon $hasta): array
    {
        $jornada = Config::jornadaSql('fecha');

        // Solo jornadas terminadas: la de hoy va a medias y las futuras no
        // pasaron, y contarlas bajaba el promedio de ese día. (Si el período es
        // solo hoy, se muestra hoy.)
        $hoy = Carbon::parse(Config::jornadaActual());
        if ($hasta->copy()->startOfDay()->gte($hoy) && $desde->copy()->startOfDay()->lt($hoy)) {
            $hasta = $hoy->copy()->subDay()->endOfDay();
        }

        // WEEKDAY: 0 = lunes … 6 = domingo, sobre la fecha de la JORNADA.
        $filas = DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw("WEEKDAY({$jornada})"))
            ->selectRaw("WEEKDAY({$jornada}) AS dia, COUNT(*) AS ventas, SUM(total) AS monto")
            ->get()
            ->keyBy(fn ($f) => (int) $f->dia);

        $jornadas = array_fill(0, 7, 0);

        for ($d = $desde->copy()->startOfDay(); $d->lte($hasta); $d->addDay()) {
            $jornadas[$d->dayOfWeekIso - 1]++;
        }

        $nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

        return array_map(fn (int $i) => [
            'dia' => $nombres[$i],
            'jornadas' => $jornadas[$i],
            'ventas' => (int) ($filas[$i]->ventas ?? 0),
            'monto' => (float) ($filas[$i]->monto ?? 0),
            'promedio' => $jornadas[$i] > 0 ? round((float) ($filas[$i]->monto ?? 0) / $jornadas[$i], 2) : 0.0,
        ], range(0, 6));
    }

    /** Comer aquí o para llevar: cuánto pesa cada uno. */
    private function porTipo(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('ventas as v')
            ->leftJoin('pedidos as p', 'p.id', '=', 'v.pedido_id')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('v.estado', '<>', 'ANULADA')
            ->groupBy('p.tipo')
            ->selectRaw('p.tipo, COUNT(*) AS ventas, SUM(v.total) AS monto, ROUND(AVG(v.total), 2) AS ticket')
            ->orderByDesc('monto')
            ->get()
            ->map(fn ($f) => (object) [
                'tipo' => match ($f->tipo) {
                    'LOCAL' => 'Comer aquí',
                    'LLEVAR' => 'Para llevar',
                    default => 'Sin pedido',
                },
                'ventas' => (int) $f->ventas,
                'monto' => (float) $f->monto,
                'ticket' => (float) $f->ticket,
            ]);
    }

    // ------------------------------------------------------------- control

    /**
     * Las ventas anuladas del período, con quién las cobró, quién las anuló y
     * por qué. El conteo solo no alcanza: una anulación es plata que entró y
     * salió, y es lo primero que se revisa cuando la caja no cuadra.
     */
    private function anuladas(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('ventas as v')
            ->join('usuarios as cobro', 'cobro.id', '=', 'v.usuario_id')
            ->leftJoin('usuarios as anulo', 'anulo.id', '=', 'v.anulada_por')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('v.estado', 'ANULADA')
            ->orderByDesc('v.fecha')
            ->select('v.id', 'v.fecha', 'v.total', 'v.motivo_anulacion')
            // El último documento de la venta: si se sustituyó, el vigente al anular.
            ->selectSub(fn ($q) => $q->from('comprobantes')->whereColumn('venta_id', 'v.id')
                ->orderByDesc('id')->limit(1)->select('numero_completo'), 'numero_completo')
            ->selectRaw('cobro.usuario AS cobro, anulo.usuario AS anulo')
            ->get();
    }

    /**
     * Los turnos cerrados en el período con lo que se esperaba en el cajón,
     * lo que se contó y la diferencia: un faltante que se repite con el mismo
     * cajero se ve aquí, no en un arqueo suelto.
     */
    private function cuadresDeCaja(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('sesiones_caja as s')
            ->join('cajas as c', 'c.id', '=', 's.caja_id')
            ->join('usuarios as u', 'u.id', '=', 's.usuario_apertura_id')
            ->leftJoin('usuarios as cierra', 'cierra.id', '=', 's.usuario_cierre_id')
            ->where('s.estado', 'CERRADA')
            ->whereBetween('s.fecha_cierre', Config::momentosDeJornadas($desde, $hasta))
            ->orderByDesc('s.fecha_cierre')
            ->select('s.id', 's.fecha_apertura', 's.fecha_cierre', 's.monto_esperado', 's.monto_declarado', 's.diferencia', 's.fondo_dejado', 's.observacion_cierre')
            ->selectRaw('c.nombre AS caja, u.usuario, cierra.usuario AS cerro')
            // Lo que se llevó del cajón: lo contado menos el fondo que quedó
            // para el turno siguiente. Es la plata que el dueño recibe cada
            // cierre. Sin fondo anotado (cierres viejos) no se sabe.
            ->selectRaw('IF(s.fondo_dejado IS NULL, NULL, ROUND(s.monto_declarado - s.fondo_dejado, 2)) AS retirado')
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
            // El costo queda congelado en cada línea al vender (solo lo que
            // lleva stock: las bebidas que se compran). Lo vendido de esas
            // mismas líneas va aparte, para que la ganancia no mezcle líneas
            // sin costo conocido.
            ->selectRaw('SUM(IF(d.costo_unitario IS NULL, 0, ROUND(d.cantidad * d.costo_unitario, 2))) AS costo')
            ->selectRaw("SUM(IF(d.costo_unitario IS NULL, 0, {$neto})) AS vendido_con_costo")
            ->selectRaw('SUM(d.costo_unitario IS NOT NULL) AS lineas_con_costo')
            ->orderByDesc('monto_vendido')
            ->get();
    }

    /**
     * Cuánto deja lo que tiene costo: lo vendido menos lo que costó, por
     * ítem, de lo que más deja a lo que menos. Los platos no tienen costo
     * cargado —se preparan con insumos que no se inventarían— y no salen.
     */
    public static function ganancias(Collection $masVendidos): Collection
    {
        return $masVendidos
            ->filter(fn ($p) => (int) $p->lineas_con_costo > 0)
            ->map(function ($p) {
                $vendido = (float) $p->vendido_con_costo;
                $costo = (float) $p->costo;

                return (object) [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'nombre' => $p->nombre,
                    'categoria' => $p->categoria,
                    'vendido' => $vendido,
                    'costo' => $costo,
                    'ganancia' => round($vendido - $costo, 2),
                    // Regalado (vendido en cero): no hay margen que calcular.
                    'margen' => $vendido > 0 ? ($vendido - $costo) / $vendido : null,
                ];
            })
            ->sortByDesc('ganancia')
            ->values();
    }

    /** Cuánto aporta cada sección de la carta: bebidas, entradas, platos… */
    private function porCategoria(Carbon $desde, Carbon $hasta): Collection
    {
        $neto = 'ROUND(d.importe * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2)';

        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('productos as p', 'p.id', '=', 'd.producto_id')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->groupBy('c.id', 'c.nombre')
            ->selectRaw('c.nombre AS categoria')
            ->selectRaw('SUM(d.cantidad) AS unidades')
            ->selectRaw("SUM({$neto}) AS monto")
            ->orderByDesc('monto')
            ->get();
    }

    /**
     * Lo que está en la carta y no se vendió ni una vez en el período: el
     * primer candidato a revisar (precio, foto, nombre) o a sacar del menú.
     */
    private function sinVentas(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('productos as p')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', 1)
            ->whereNotExists(fn ($q) => $q->from('venta_detalle as d')
                ->join('ventas as v', 'v.id', '=', 'd.venta_id')
                ->whereColumn('d.producto_id', 'p.id')
                ->where('v.estado', '<>', 'ANULADA')
                ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta)))
            ->orderBy('c.nombre')
            ->orderBy('p.nombre')
            ->select('p.id', 'p.codigo', 'p.nombre')
            ->selectRaw('c.nombre AS categoria')
            ->get();
    }
}
