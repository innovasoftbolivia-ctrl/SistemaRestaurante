<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Los precios del catálogo cuando el negocio cambia cómo trabaja el impuesto.
 *
 * `productos.precio_venta` significa cosas distintas en cada modo: con el
 * impuesto encima es la base y el cliente paga la base más la tasa; con el
 * impuesto incluido es lo que paga el cliente. Cambiar de modo sin convertir
 * los precios le cambia el precio a todo el mostrador de golpe: 13 % más caro
 * o 13 % más barato. Por eso, al cambiar de modo, se ajustan para que el
 * cliente siga pagando lo mismo.
 *
 * Solo los productos afectos: un producto exonerado cuesta lo mismo en los
 * dos modos. Las ventas ya hechas no se tocan: cada una guarda su modo.
 */
class Precios
{
    /**
     * @param  float  $tasaAnterior  la tasa con la que se cobraba hasta ahora
     * @param  float  $tasaNueva  la que rige desde ahora
     * @return int cuántos precios cambiaron
     */
    public static function convertirAlModo(bool $incluido, float $tasaAnterior, float $tasaNueva): int
    {
        if ($incluido) {
            // El cliente pagaba base × (1 + tasa): ese pasa a ser el precio.
            // ROUND de MySQL, igual que el precio de estante que ya veía.
            if ($tasaAnterior <= 0) {
                return 0;
            }

            return DB::update(
                'UPDATE productos SET precio_venta = ROUND(precio_venta * (1 + ?), 2) WHERE afecto_impuesto = 1',
                [number_format($tasaAnterior, 4, '.', '')],
            );
        }

        // El cliente pagaba el precio: la base es el precio sin la tasa.
        if ($tasaNueva <= 0) {
            return 0;
        }

        return DB::update(
            'UPDATE productos SET precio_venta = ROUND(precio_venta / (1 + ?), 2) WHERE afecto_impuesto = 1',
            [number_format($tasaNueva, 4, '.', '')],
        );
    }
}
