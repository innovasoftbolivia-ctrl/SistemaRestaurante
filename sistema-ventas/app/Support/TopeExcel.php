<?php

namespace App\Support;

/**
 * Cuántas filas se exportan a Excel antes de pedir un período más corto.
 *
 * PhpSpreadsheet arma el libro entero en memoria antes de empezar a mandarlo,
 * a razón de un kilobyte y pico por celda: un año de Libro de Ventas con
 * miles de facturas y 17 columnas agotaba la memoria del servidor y el usuario
 * recibía un error 500 en vez del archivo. El PDF ya tenía su tope
 * ({@see TopePdf}) y mandaba al Excel; el Excel no tenía ninguno.
 *
 * Se ajusta con `EXCEL_MAX_FILAS`.
 */
class TopeExcel
{
    public static function maximo(): int
    {
        return max(1, (int) config('ventas.excel_max_filas', 20000));
    }

    /** El aviso para el usuario, o null si el Excel se puede generar. */
    public static function excedido(array $documento): ?string
    {
        $filas = TopePdf::filas($documento);

        if ($filas <= self::maximo()) {
            return null;
        }

        return sprintf(
            'Este reporte tiene %s filas y el Excel admite hasta %s. Acorta el período y descárgalo por partes.',
            number_format($filas, 0, '.', ','),
            number_format(self::maximo(), 0, '.', ','),
        );
    }
}
