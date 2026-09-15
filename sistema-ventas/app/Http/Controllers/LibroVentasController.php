<?php

namespace App\Http\Controllers;

use App\Services\Hojas;
use App\Services\LibroDeVentas;
use App\Support\Config;
use App\Support\TopePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes > Libro de Ventas IVA. Ver App\Services\LibroDeVentas: es un
 * borrador para el contador, y el débito fiscal se calcula como en Bolivia.
 *
 * La pantalla, el Excel y el PDF salen del MISMO documento, igual que en el
 * resto de los reportes: así no se pueden separar con el tiempo.
 */
class LibroVentasController extends Controller
{
    private const CABECERAS = [
        'N°', 'Fecha', 'N° de factura', 'Cód. de autorización', 'NIT / CI', 'Nombre o razón social',
        'Importe total (A)', 'No sujeto a IVA (B)', 'Exentas (C)', 'Tasa cero (D)', 'Subtotal (E)',
        'Descuentos (F)', 'Base débito fiscal (G)', 'Débito fiscal 13 % (H)', 'Estado', 'Cód. de control',
        'Impuesto del ticket',
    ];

    private const FORMATOS = [
        'entero', 'fecha', null, null, null, null,
        'moneda', 'moneda', 'moneda', 'moneda', 'moneda',
        'moneda', 'moneda', 'moneda', null, null,
        'moneda',
    ];

    public function index(Request $request): View
    {
        [$anio, $mes] = $this->periodo($request);
        $libro = LibroDeVentas::mes($anio, $mes);

        return view('reportes.libro-ventas', [
            'title' => 'Libro de Ventas IVA',
            'libro' => $libro,
            'mes' => sprintf('%04d-%02d', $anio, $mes),
            'moneda' => Config::moneda(),
        ]);
    }

    public function excel(Request $request): StreamedResponse
    {
        [$anio, $mes] = $this->periodo($request);

        return (new Hojas($this->documento(LibroDeVentas::mes($anio, $mes))))
            ->descargar(sprintf('libro-ventas-iva_%04d-%02d.xlsx', $anio, $mes));
    }

    public function pdf(Request $request): Response|RedirectResponse
    {
        [$anio, $mes] = $this->periodo($request);
        $documento = $this->documento(LibroDeVentas::mes($anio, $mes));

        if ($aviso = TopePdf::excedido($documento)) {
            return back()->with('error', $aviso);
        }

        return Pdf::loadView('reportes.pdf', ['doc' => $documento])
            ->setPaper('a4', 'landscape')
            ->download(sprintf('libro-ventas-iva_%04d-%02d.pdf', $anio, $mes));
    }

    /**
     * El mes pedido como «2026-09», o el actual. Un valor que no es un mes no
     * da error: cae en el mes actual, como el resto de los filtros de fecha.
     *
     * @return array{0: int, 1: int}
     */
    private function periodo(Request $request): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $request->query('mes'), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) now()->format('Y'), (int) now()->format('n')];
    }

    /** @param  array<string, mixed>  $libro */
    private function documento(array $libro): array
    {
        $t = $libro['totales'];
        $validas = collect($libro['filas'])->where('estado', 'V');

        return [
            'titulo' => 'Libro de Ventas IVA',
            'negocio' => [
                'nombre' => Config::negocio(),
                'documento' => Config::get('negocio_documento'),
                'direccion' => Config::get('negocio_direccion'),
                'telefono' => Config::get('negocio_telefono'),
            ],
            'moneda' => Config::moneda(),
            'periodo' => 'Período '.$libro['desde']->translatedFormat('F \d\e Y'),
            'generado' => now()->format('d/m/Y H:i'),
            'orientacion' => 'landscape',
            'indicadores' => [
                ['etiqueta' => 'Facturas válidas', 'valor' => $validas->count(), 'formato' => 'entero'],
                ['etiqueta' => 'Anuladas', 'valor' => count($libro['filas']) - $validas->count(), 'formato' => 'entero'],
                ['etiqueta' => 'Importe facturado', 'valor' => $t['importe_total'], 'formato' => 'moneda'],
                ['etiqueta' => 'Débito fiscal', 'valor' => $t['debito_fiscal'], 'formato' => 'moneda',
                    'nota' => '13 % de la base, calculado como en Bolivia', 'destacar' => true],
            ],
            'tablas' => [[
                'nombre' => 'Facturas del mes',
                'nota' => 'BORRADOR PARA EL CONTADOR. Sin código de autorización ni de control: el sistema todavía no '
                    .'factura con el SIN. El débito fiscal es el 13 % de lo cobrado; la última columna es el impuesto '
                    .'que muestra el ticket, que el sistema calcula de otra forma, solo como referencia.',
                'cabeceras' => self::CABECERAS,
                'formatos' => self::FORMATOS,
                'alineacion' => ['der', 'izq', 'izq', 'izq', 'izq', 'izq', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'izq', 'izq', 'der'],
                'filas' => array_map(fn (array $f) => [
                    $f['numero'], $f['fecha'], $f['factura'], $f['autorizacion'] ?? '—', $f['documento'], $f['cliente'],
                    $f['importe_total'], $f['no_sujeto'], $f['exentas'], $f['tasa_cero'], $f['subtotal'],
                    $f['descuentos'], $f['base_debito'], $f['debito_fiscal'], $f['estado'], $f['codigo_control'] ?? '—',
                    $f['impuesto_ticket'],
                ], $libro['filas']),
                'totales' => [
                    'Total', null, null, null, null, null,
                    $t['importe_total'], $t['no_sujeto'], $t['exentas'], $t['tasa_cero'], $t['subtotal'],
                    $t['descuentos'], $t['base_debito'], $t['debito_fiscal'], null, null,
                    $t['impuesto_ticket'],
                ],
                'vacia' => 'No se emitieron facturas en el mes.',
            ]],
        ];
    }
}
