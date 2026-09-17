<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Convierte un reporte —el array que arma `ReporteController`— en un libro de
 * Excel presentable.
 *
 * Dos decisiones que gobiernan todo lo demás:
 *
 * 1. Los importes, cantidades y fechas van como DATOS, no como texto ya
 *    formateado. Un «Bs 1.234,50» se ve bien y no se deja sumar, ordenar ni
 *    meter en una tabla dinámica. El formato se aplica a la celda.
 *
 * 2. El mismo array alimenta el PDF (`reportes.pdf`). Si una cifra cambia, los
 *    dos documentos cambian a la vez.
 */
class Hojas
{
    private const MARCA = 'FF465FFF';        // brand-500

    private const MARCA_OSCURA = 'FF2A31D8'; // brand-700

    private const TINTA = 'FF101828';        // gray-900

    private const TENUE = 'FF667085';        // gray-500

    private const LINEA = 'FFE4E7EC';        // gray-200

    private const BANDA = 'FFF9FAFB';        // gray-50

    private Spreadsheet $libro;

    public function __construct(private array $doc)
    {
        $this->libro = new Spreadsheet;

        $this->libro->getProperties()
            ->setCreator($doc['negocio']['nombre'])
            ->setCompany($doc['negocio']['nombre'])
            ->setTitle($doc['titulo'])
            ->setSubject($doc['periodo'])
            ->setDescription('Generado por el Sistema de Ventas el '.$doc['generado']);

        $this->portada();

        foreach ($doc['tablas'] as $tabla) {
            $this->tabla($tabla);
        }

        $this->libro->setActiveSheetIndex(0);
    }

    public function descargar(string $nombreFichero): StreamedResponse
    {
        $escritor = new Xlsx($this->libro);

        return response()->streamDownload(function () use ($escritor) {
            $escritor->save('php://output');
        }, $nombreFichero, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-store',
        ]);
    }

    // ---------------------------------------------------------------- portada

    /** Hoja de entrada: cabecera del negocio y los indicadores del período. */
    private function portada(): void
    {
        $hoja = $this->libro->getActiveSheet();
        $hoja->setTitle('Resumen');
        $hoja->getTabColor()->setARGB(self::MARCA);
        $hoja->setShowGridlines(false);

        $fila = $this->cabeceraDocumento($hoja, 4);
        $fila++;

        // --- indicadores, en tres columnas: etiqueta | valor | nota
        $this->poner($hoja, [1, $fila], 'INDICADORES DEL PERÍODO');
        $hoja->mergeCells([1, $fila, 4, $fila]);
        $this->tituloSeccion($hoja, $fila, 4);
        $fila++;

        $primera = $fila;

        foreach ($this->doc['indicadores'] as $ind) {
            $this->poner($hoja, [1, $fila], $ind['etiqueta']);
            $hoja->mergeCells([1, $fila, 2, $fila]);
            $this->poner($hoja, [3, $fila], $ind['valor']);
            $this->poner($hoja, [4, $fila], $ind['nota'] ?? '');

            $hoja->getStyle([1, $fila])->getFont()->setBold(true)->getColor()->setARGB(self::TINTA);
            $hoja->getStyle([3, $fila])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $hoja->getStyle([3, $fila])->getFont()->setBold(true);
            $hoja->getStyle([4, $fila])->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB(self::TENUE);

            if (isset($ind['formato'])) {
                $this->formatear($hoja, 3, $fila, $fila, $ind['formato']);
            }

            // Banda alterna: se lee mejor una lista larga de cifras.
            if (($fila - $primera) % 2 === 1) {
                $hoja->getStyle([1, $fila, 4, $fila])->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BANDA);
            }

            $hoja->getRowDimension($fila)->setRowHeight(18);
            $fila++;
        }

        $hoja->getStyle([1, $primera, 4, $fila - 1])->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::LINEA);

        $fila += 2;
        $this->poner($hoja, [1, $fila], 'Este libro tiene una hoja por cada desglose del reporte.');
        $hoja->getStyle([1, $fila])->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB(self::TENUE);

        foreach ([38, 14, 18, 44] as $i => $ancho) {
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth($ancho);
        }

        $this->prepararImpresion($hoja, 4, null);
    }

    // ----------------------------------------------------------------- tablas

    private function tabla(array $tabla): void
    {
        $hoja = $this->libro->createSheet();
        $hoja->setTitle($this->nombrePestana($tabla['nombre']));
        $hoja->getTabColor()->setARGB(self::MARCA_OSCURA);
        $hoja->setShowGridlines(false);

        $columnas = count($tabla['cabeceras']);
        $fila = $this->cabeceraDocumento($hoja, $columnas);
        $fila++;

        $this->poner($hoja, [1, $fila], mb_strtoupper($tabla['nombre']));
        $hoja->mergeCells([1, $fila, $columnas, $fila]);
        $this->tituloSeccion($hoja, $fila, $columnas);
        $fila++;

        if (! empty($tabla['nota'])) {
            $this->poner($hoja, [1, $fila], $tabla['nota']);
            $hoja->mergeCells([1, $fila, $columnas, $fila]);
            $hoja->getStyle([1, $fila])->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB(self::TENUE);
            $fila++;
        }

        $filaCabecera = $fila;
        foreach ($tabla['cabeceras'] as $i => $texto) {
            $this->poner($hoja, [$i + 1, $filaCabecera], $texto);
        }
        $this->estilarCabecera($hoja, $filaCabecera, $columnas, $tabla['alineacion'] ?? []);
        $fila++;

        $primeraDato = $fila;
        $n = 0;

        foreach ($tabla['filas'] as $datos) {
            foreach (array_values($datos) as $i => $valor) {
                $formato = $tabla['formatos'][$i] ?? null;

                if ($formato === 'fecha' && $valor !== null && $valor !== '') {
                    // Una fecha tiene que entrar como fecha: si va como texto,
                    // Excel la ordena alfabéticamente y no agrupa por mes.
                    $valor = Date::PHPToExcel(
                        $valor instanceof \DateTimeInterface ? $valor : new \DateTimeImmutable((string) $valor)
                    );
                }

                $this->poner($hoja, [$i + 1, $fila], $valor);
            }

            if ($n % 2 === 1) {
                $hoja->getStyle([1, $fila, $columnas, $fila])->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BANDA);
            }

            $fila++;
            $n++;
        }

        $ultimaDato = $fila - 1;

        if ($n === 0) {
            $this->poner($hoja, [1, $fila], $tabla['vacia'] ?? 'Sin datos en el período.');
            $hoja->mergeCells([1, $fila, $columnas, $fila]);
            $hoja->getStyle([1, $fila])->getFont()->setItalic(true)->getColor()->setARGB(self::TENUE);
            $hoja->getStyle([1, $fila])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ultimaDato = $fila;
            $fila++;
        }

        // --- fila de totales
        if ($n > 0 && ! empty($tabla['totales'])) {
            foreach (array_values($tabla['totales']) as $i => $valor) {
                if ($valor !== null) {
                    $this->poner($hoja, [$i + 1, $fila], $valor);
                }
            }

            $estilo = $hoja->getStyle([1, $fila, $columnas, $fila]);
            $estilo->getFont()->setBold(true)->getColor()->setARGB(self::TINTA);
            $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MARCA);
            $hoja->getRowDimension($fila)->setRowHeight(20);
            $fila++;
        }

        $this->aplicarFormatos($hoja, $tabla['formatos'] ?? [], $primeraDato, $fila - 1);
        $this->alinear($hoja, $tabla['alineacion'] ?? [], $primeraDato, $fila - 1);

        $hoja->getStyle([1, $filaCabecera, $columnas, max($ultimaDato, $filaCabecera)])
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::LINEA);

        // Filtrar y ordenar sin perder de vista los títulos.
        if ($n > 0) {
            $ultimaColumna = Coordinate::stringFromColumnIndex($columnas);
            $hoja->setAutoFilter('A'.$filaCabecera.':'.$ultimaColumna.$ultimaDato);
        }
        $hoja->freezePane([1, $filaCabecera + 1]);

        foreach (range(1, $columnas) as $c) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $this->prepararImpresion($hoja, $columnas, $filaCabecera);
    }

    // ----------------------------------------------------------------- piezas

    /** Bloque superior con el negocio y el período. Devuelve la fila siguiente. */
    private function cabeceraDocumento(Worksheet $hoja, int $columnas): int
    {
        $negocio = $this->doc['negocio'];

        $this->poner($hoja, [1, 1], $negocio['nombre']);
        $hoja->mergeCells([1, 1, $columnas, 1]);
        $hoja->getStyle([1, 1])->getFont()->setBold(true)->setSize(16)->getColor()->setARGB(self::MARCA);
        $hoja->getRowDimension(1)->setRowHeight(24);

        $datos = array_filter([
            $negocio['documento'] ? 'NIT '.$negocio['documento'] : null,
            $negocio['direccion'],
            $negocio['telefono'],
        ]);

        $this->poner($hoja, [1, 2], $this->doc['titulo'].($datos ? '  ·  '.implode('  ·  ', $datos) : ''));
        $hoja->mergeCells([1, 2, $columnas, 2]);
        $hoja->getStyle([1, 2])->getFont()->setSize(10)->getColor()->setARGB(self::TENUE);

        $this->poner($hoja, [1, 3], $this->doc['periodo'].'  ·  Generado el '.$this->doc['generado']);
        $hoja->mergeCells([1, 3, $columnas, 3]);
        $hoja->getStyle([1, 3])->getFont()->setSize(10)->getColor()->setARGB(self::TENUE);

        return 4;
    }

    private function tituloSeccion(Worksheet $hoja, int $fila, int $columnas): void
    {
        $estilo = $hoja->getStyle([1, $fila, $columnas, $fila]);
        $estilo->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(self::MARCA_OSCURA);
        $estilo->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MARCA);
        $hoja->getRowDimension($fila)->setRowHeight(22);
    }

    /** @param  array<int, string>  $alineacion */
    private function estilarCabecera(Worksheet $hoja, int $fila, int $columnas, array $alineacion): void
    {
        $estilo = $hoja->getStyle([1, $fila, $columnas, $fila]);
        $estilo->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::MARCA);
        $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $hoja->getRowDimension($fila)->setRowHeight(24);

        $this->alinear($hoja, $alineacion, $fila, $fila);
    }

    /** @param  array<int, string>  $alineacion */
    private function alinear(Worksheet $hoja, array $alineacion, int $desde, int $hasta): void
    {
        if ($hasta < $desde) {
            return;
        }

        foreach ($alineacion as $i => $lado) {
            $hoja->getStyle([$i + 1, $desde, $i + 1, $hasta])->getAlignment()->setHorizontal(match ($lado) {
                'der' => Alignment::HORIZONTAL_RIGHT,
                'centro' => Alignment::HORIZONTAL_CENTER,
                default => Alignment::HORIZONTAL_LEFT,
            });
        }
    }

    /** @param  array<int, string>  $formatos */
    private function aplicarFormatos(Worksheet $hoja, array $formatos, int $desde, int $hasta): void
    {
        if ($hasta < $desde) {
            return;
        }

        foreach ($formatos as $i => $tipo) {
            // Las columnas de texto vienen con null: no llevan formato numérico.
            if ($tipo !== null) {
                $this->formatear($hoja, $i + 1, $desde, $hasta, $tipo);
            }
        }
    }

    private function formatear(Worksheet $hoja, int $columna, int $desde, int $hasta, string $tipo): void
    {
        // Sin símbolo de moneda incrustado: el negocio puede cambiarlo en la
        // configuración y un símbolo dentro del fichero envejece mal. Va en la
        // cabecera del documento.
        $mapa = [
            'moneda' => '#,##0.00',
            'entero' => '#,##0',
            'decimal' => '#,##0.###',
            'porcentaje' => '0.0%',
            'fecha' => 'dd/mm/yyyy',
        ];

        if (isset($mapa[$tipo])) {
            $hoja->getStyle([$columna, $desde, $columna, $hasta])
                ->getNumberFormat()->setFormatCode($mapa[$tipo]);
        }
    }

    /** Que salga bien si lo mandan a la impresora, no solo en pantalla. */
    private function prepararImpresion(Worksheet $hoja, int $columnas, ?int $filaCabecera): void
    {
        $config = $hoja->getPageSetup();
        $config->setOrientation($columnas > 5 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $config->setPaperSize(PageSetup::PAPERSIZE_A4);
        $config->setFitToWidth(1);
        $config->setFitToHeight(0);

        $hoja->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);

        // La cabecera se repite en cada página impresa; sin esto, a partir de la
        // segunda hoja las columnas son números sin nombre.
        if ($filaCabecera !== null) {
            $config->setRowsToRepeatAtTopByStartAndEnd($filaCabecera, $filaCabecera);
        }

        // En el pie, `&` es un código de Excel (&P es la página): un negocio
        // llamado «Pérez & Hijos» imprimía «Pérez Hijos» con basura. Se dobla.
        $nombre = str_replace('&', '&&', $this->doc['negocio']['nombre']);
        $hoja->getHeaderFooter()->setOddFooter('&L&9'.$nombre.'&C&9&P / &N&R&9'.$this->doc['generado']);
    }

    /**
     * Toda celda entra por aquí. Un texto que empieza con = + - @ se guarda
     * como texto, nunca como fórmula: un cliente dado de alta como
     * «=HYPERLINK(...)» salía como fórmula viva en el Libro de Ventas de quien
     * lo abriera. Antes solo lo filtraban las filas de datos; el nombre del
     * negocio, los títulos, las notas y los totales entraban sin pasar por él.
     *
     * @param  array{0: int, 1: int}  $celda
     */
    private function poner(Worksheet $hoja, array $celda, mixed $valor): void
    {
        if (is_string($valor) && preg_match('/^[=+\-@\t\r]/', $valor)) {
            $hoja->setCellValueExplicit($celda, $valor, DataType::TYPE_STRING);

            return;
        }

        $hoja->setCellValue($celda, $valor);
    }

    /** Excel corta a 31 caracteres y no admite : \ / ? * [ ] */
    private function nombrePestana(string $nombre): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $nombre), 0, 31);
    }
}
