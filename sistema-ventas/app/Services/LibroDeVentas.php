<?php

namespace App\Services;

use App\Support\Config;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El Libro de Ventas IVA de un mes: una fila por factura emitida.
 *
 * BORRADOR PARA EL CONTADOR, no el libro que se presenta. El sistema todavía no
 * factura con el SIN, así que no hay código de autorización ni código de
 * control, y las columnas de abajo siguen el formato estándar en lo general
 * pero no fueron validadas contra la versión vigente del SIAT. Sirve para que
 * el contador no tenga que armar el mes a mano desde los comprobantes.
 *
 * EL DÉBITO FISCAL SE CALCULA «POR DENTRO», COMO EN BOLIVIA: el 13 % del
 * importe facturado. No es el impuesto que muestra el ticket. El sistema
 * calcula el impuesto «por fuera» —le suma la tasa a una base sin impuesto,
 * como el IGV peruano del que viene—, y con la misma tasa del 13 % eso da el
 * 11,5 % de lo cobrado: en un precio de estante de 4,50, el ticket dice 0,52 y
 * el débito fiscal boliviano es 0,585. Por eso el libro no copia
 * `comprobantes.impuesto`: lo recalcula sobre lo cobrado, y muestra el del
 * ticket al lado solo como referencia, para que la diferencia quede a la vista
 * hasta que se decida cómo calcularlo en todo el sistema.
 *
 * Qué entra:
 *   - Solo FACTURAS. Los recibos no van en el libro.
 *   - Las anuladas y las sustituidas van con estado «A» y todos los importes
 *     en cero, que es como se declara una factura que no vale.
 *   - Las devoluciones no restan acá: van por notas de crédito, que son otro
 *     registro.
 */
class LibroDeVentas
{
    /** El IVA boliviano, que es una alícuota fija de ley y no la tasa configurable. */
    public const IVA = 0.13;

    /** La misma alícuota en centésimas, para calcular en centavos enteros. */
    private const IVA_CENTESIMAS = 13;

    /**
     * @return array{
     *     desde: CarbonImmutable,
     *     hasta: CarbonImmutable,
     *     filas: array<int, array<string, mixed>>,
     *     totales: array<string, float>
     * }
     */
    public static function mes(int $anio, int $mes): array
    {
        $desde = CarbonImmutable::create($anio, $mes, 1)->startOfDay();
        $hasta = $desde->endOfMonth();

        $facturas = DB::table('comprobantes as c')
            ->join('series_comprobante as s', 's.id', '=', 'c.serie_id')
            ->join('tipos_comprobante as t', 't.id', '=', 's.tipo_comprobante_id')
            ->where('t.codigo', 'FAC')
            ->whereBetween('c.fecha_emision', [$desde, $hasta])
            ->orderBy('c.fecha_emision')
            ->orderBy('c.id')
            ->get([
                'c.id', 'c.venta_id', 'c.fecha_emision', 'c.numero_completo', 'c.estado',
                'c.cliente_tipo_documento', 'c.cliente_documento', 'c.cliente_nombre',
                'c.subtotal', 'c.descuento', 'c.impuesto', 'c.total',
            ]);

        // Lo que se vendió sin impuesto en cada venta, de una sola consulta.
        // Exento es lo que NO pagó impuesto: el producto exonerado y también
        // lo vendido con la tasa en cero (un negocio que todavía no factura con
        // IVA). Sin esta segunda condición el libro declaraba el 13 % de
        // facturas cuyo ticket no cobró ni un centavo de impuesto.
        $sinImpuesto = DB::table('venta_detalle')
            ->whereIn('venta_id', $facturas->pluck('venta_id')->unique())
            ->where(fn ($q) => $q->where('afecto_impuesto', 0)->orWhere('tasa_impuesto', 0))
            ->groupBy('venta_id')
            ->selectRaw('venta_id, SUM(importe) AS importe')
            ->pluck('importe', 'venta_id');

        $filas = [];
        $numero = 0;

        foreach ($facturas as $f) {
            $valida = $f->estado === 'EMITIDO';

            // Todo en CENTAVOS ENTEROS. Es plata que se declara, y en coma
            // flotante 6,50 × 0,13 no es 0,845 sino 0,84499999…: según la versión
            // de PHP, redondea a 0,84 o a 0,85. PHP cambió justo ese redondeo en
            // la 8.4, y el paquete del hosting está fijado a la 8.3.
            $a = $valida ? self::centavos($f->total) : 0;

            // Las exentas llevan también su parte del descuento de cabecera,
            // prorrateado sobre el subtotal igual que lo hace el resto del sistema.
            $subtotal = self::centavos($f->subtotal);
            $neto = $subtotal - self::centavos($f->descuento);
            $c = $valida && $subtotal > 0
                ? self::dividir(self::centavos($sinImpuesto[$f->venta_id] ?? 0) * $neto, $subtotal)
                : 0;

            $b = 0;          // ICE, IEHD, tasas: el sistema no los maneja
            $d = 0;          // ventas gravadas a tasa cero: tampoco
            $e = $a - $b - $c - $d;
            $descuentos = 0; // el importe cobrado ya viene con el descuento aplicado
            $base = $e - $descuentos;
            $debito = $valida ? self::dividir($base * self::IVA_CENTESIMAS, 100) : 0;

            $filas[] = [
                'numero' => ++$numero,
                'fecha' => CarbonImmutable::parse($f->fecha_emision),
                'factura' => $f->numero_completo,
                'autorizacion' => null,
                'documento' => trim(($f->cliente_tipo_documento !== 'SIN' ? $f->cliente_tipo_documento.' ' : '').($f->cliente_documento ?? '')),
                'cliente' => $f->cliente_nombre,
                'importe_total' => $a / 100.0,
                'no_sujeto' => $b / 100.0,
                'exentas' => $c / 100.0,
                'tasa_cero' => $d / 100.0,
                'subtotal' => $e / 100.0,
                'descuentos' => $descuentos / 100.0,
                'base_debito' => $base / 100.0,
                'debito_fiscal' => $debito / 100.0,
                'estado' => $valida ? 'V' : 'A',
                'codigo_control' => null,
                'impuesto_ticket' => $valida ? self::centavos($f->impuesto) / 100.0 : 0.0,
            ];
        }

        // Los totales suman centavos, no los importes ya convertidos.
        $sumar = fn (string $columna) => array_sum(array_map(
            fn (array $fila) => self::centavos($fila[$columna]),
            $filas,
        )) / 100.0;

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            // Para avisarlo en la pantalla: con la tasa en cero el libro sale
            // entero en exentas, y eso hay que explicarlo antes que el contador
            // lo pregunte.
            'sin_impuesto_configurado' => Config::tasaImpuesto() <= 0,
            'filas' => $filas,
            'totales' => [
                'importe_total' => $sumar('importe_total'),
                'no_sujeto' => $sumar('no_sujeto'),
                'exentas' => $sumar('exentas'),
                'tasa_cero' => $sumar('tasa_cero'),
                'subtotal' => $sumar('subtotal'),
                'descuentos' => $sumar('descuentos'),
                'base_debito' => $sumar('base_debito'),
                'debito_fiscal' => $sumar('debito_fiscal'),
                'impuesto_ticket' => $sumar('impuesto_ticket'),
            ],
        ];
    }

    /**
     * Un importe de dos decimales, en centavos enteros. Llega como texto desde
     * las columnas DECIMAL, así que se parte el texto en vez de multiplicar un
     * float por 100.
     */
    private static function centavos(int|float|string|null $importe): int
    {
        $texto = number_format((float) $importe, 2, '.', '');
        $negativo = str_starts_with($texto, '-');
        [$entero, $decimales] = explode('.', ltrim($texto, '-'));
        $centavos = (int) $entero * 100 + (int) $decimales;

        return $negativo ? -$centavos : $centavos;
    }

    /** División entera redondeando la mitad hacia arriba, como ROUND de MySQL. */
    private static function dividir(int $dividendo, int $divisor): int
    {
        return intdiv(2 * $dividendo + $divisor, 2 * $divisor);
    }
}
