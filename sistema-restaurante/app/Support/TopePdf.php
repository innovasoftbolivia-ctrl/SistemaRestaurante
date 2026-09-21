<?php

namespace App\Support;

/**
 * Cuántas filas aguanta un PDF antes de que dompdf se quede sin memoria.
 *
 * dompdf arma la tabla entera en memoria y el consumo crece más rápido que las
 * filas: medido con una tabla como la del Libro de Ventas (17 columnas), 150
 * filas usan 110 MB y 500 filas 436 MB; con 650 se agota el límite de 512 MB de
 * producción, y en un hosting compartido de 128–256 MB falla mucho antes. Un
 * error 500 al descargar el Libro de Ventas de fin de mes es lo peor que puede
 * pasar, y un PDF recortado no le sirve al contador.
 *
 * Por eso, pasado el tope, no se genera: se ofrece el Excel, que no tiene ese
 * límite y trae exactamente lo mismo. Se ajusta con `PDF_MAX_FILAS`.
 */
class TopePdf
{
    public static function maximo(): int
    {
        return max(1, (int) config('restaurante.pdf_max_filas', 250));
    }

    /** @param  array<string, mixed>  $documento  la estructura que comparten el PDF y el Excel */
    public static function filas(array $documento): int
    {
        return array_sum(array_map(fn (array $tabla) => count($tabla['filas'] ?? []), $documento['tablas'] ?? []));
    }

    /** El aviso para el usuario, o null si el PDF se puede generar. */
    public static function excedido(array $documento): ?string
    {
        $filas = self::filas($documento);

        if ($filas <= self::maximo()) {
            return null;
        }

        return sprintf(
            'Este reporte tiene %s filas y el PDF admite hasta %s. Descarga el Excel, que trae lo mismo, o acorta el período.',
            number_format($filas, 0, '.', ','),
            number_format(self::maximo(), 0, '.', ','),
        );
    }
}
