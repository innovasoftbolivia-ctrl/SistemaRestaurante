<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Producto;
use Illuminate\Validation\ValidationException;

/**
 * La fecha de vencimiento de lo que entra, en TODAS las puertas.
 *
 * El ingreso suelto ya la exigía a los productos con control de vencimiento;
 * la compra, el alta con stock inicial y lo que repone el proveedor la dejaban
 * opcional, y esa mercadería quedaba en un lote sin fecha: fuera de las
 * alertas y última en salir. Tampoco puede ser una fecha pasada.
 */
trait ExigeVencimiento
{
    /**
     * @param  array<int|string, int>  $productoPorLinea  índice de la línea => id del producto
     * @param  array<int|string, mixed>  $fechas  índice de la línea => fecha escrita
     */
    protected function exigirVencimiento(array $productoPorLinea, array $fechas, string $campo): void
    {
        $perecederos = Producto::whereIn('id', array_unique(array_values($productoPorLinea)))
            ->where('controla_vencimiento', 1)
            ->pluck('nombre', 'id');

        $errores = [];

        foreach ($productoPorLinea as $i => $productoId) {
            if (! $perecederos->has($productoId)) {
                continue;
            }

            $fecha = $fechas[$i] ?? null;
            $nombre = $perecederos[$productoId];

            if (blank($fecha)) {
                $errores["lineas.{$i}.{$campo}"] = "«{$nombre}» se controla por vencimiento: escribe la fecha de la tanda que llegó.";
            } elseif (strtotime((string) $fecha) < strtotime('today')) {
                $errores["lineas.{$i}.{$campo}"] = "La fecha de vencimiento de «{$nombre}» ya pasó: no se ingresa mercadería vencida.";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }
}
