<?php

namespace App\Services;

use App\Models\Producto;

/**
 * El costo de referencia de un producto: `productos.precio_compra`.
 *
 * De ahí salen la ganancia por unidad, el margen y el valor del inventario, así
 * que moverlo cambia lo que dicen todos los reportes. Por eso el cambio no es
 * automático nunca —lo pide quien recibe la mercadería, línea por línea— y por
 * eso queda auditado cuando ocurre.
 *
 * Vive aquí y no dentro de la pantalla de ingreso porque hay dos sitios que lo
 * necesitan: el ingreso suelto de una línea y el registro de una compra
 * completa. Repetido en los dos, el día que cambie el criterio cambiaría en uno
 * solo, y el costo del producto dependería de por qué puerta entró la caja.
 */
class Costos
{
    /**
     * Deja el costo del producto en `$costo`, si de verdad cambia.
     *
     * @param  string  $origen  De dónde viene el cambio, para la bitácora.
     * @return array{anterior: float, nuevo: float}|null null si no hubo cambio
     */
    public static function aplicar(Producto $producto, ?float $costo, string $origen): ?array
    {
        if ($costo === null) {
            return null;
        }

        $anterior = round((float) $producto->precio_compra, 2);
        $nuevo = round($costo, 2);

        if ($anterior === $nuevo) {
            return null;
        }

        // `forceFill` y no `update`: `precio_compra` es asignable en masa, pero
        // aquí se escribe una sola columna a propósito, sin arrastrar nada más
        // de lo que traiga el modelo en memoria.
        $producto->forceFill(['precio_compra' => $nuevo])->save();

        Auditor::registrar('CAMBIO_COSTO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'anterior' => $anterior,
            'nuevo' => $nuevo,
            'origen' => $origen,
        ]);

        return ['anterior' => $anterior, 'nuevo' => $nuevo];
    }
}
