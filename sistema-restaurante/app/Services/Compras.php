<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La factura del proveedor, entera y de una vez: cada línea sube el stock y
 * deja el costo por unidad como el último costo del producto (el que la
 * venta congela para calcular la ganancia).
 */
class Compras
{
    /**
     * @param  array<int, array{producto_id: int, cantidad: float|int|string, costo_unitario: float|int|string}>  $lineas
     *                                                                                                                     cantidad y costo ya en UNIDADES (la pantalla convierte cajas y sueltas)
     */
    public static function registrar(
        Proveedor $proveedor,
        Usuario $usuario,
        array $lineas,
        ?string $documentoExterno = null,
        ?string $observacion = null,
    ): Compra {
        if ($lineas === []) {
            throw new RuntimeException('La compra no tiene ningún producto.');
        }

        if (! $proveedor->activo) {
            throw new RuntimeException("El proveedor «{$proveedor->razon_social}» está desactivado.");
        }

        $ids = array_map(fn ($l) => (int) $l['producto_id'], $lineas);

        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('La compra repite un producto: júntalo en una sola línea.');
        }

        return DB::transaction(function () use ($proveedor, $usuario, $lineas, $documentoExterno, $observacion) {
            $compra = Compra::create([
                'proveedor_id' => $proveedor->id,
                'usuario_id' => $usuario->id,
                'documento_externo' => $documentoExterno ?: null,
                'fecha' => now(),
                'observacion' => $observacion ?: null,
            ]);

            // En orden de producto, igual que la venta: las filas se bloquean
            // siempre en el mismo orden.
            usort($lineas, fn ($a, $b) => (int) $a['producto_id'] <=> (int) $b['producto_id']);

            foreach ($lineas as $linea) {
                $producto = Producto::findOrFail((int) $linea['producto_id']);
                $cantidad = round((float) $linea['cantidad'], 3);
                // Cuatro decimales: el costo por unidad sale de dividir la caja
                // (25,00 / 12 = 2,0833), y a dos la compra ya no sumaba la factura.
                $costo = round((float) $linea['costo_unitario'], 4);

                if (! $producto->controla_stock) {
                    throw new RuntimeException("«{$producto->nombre}» no lleva inventario: actívalo en su ficha del menú antes de comprarlo.");
                }

                if ($cantidad <= 0) {
                    throw new RuntimeException("La cantidad de «{$producto->nombre}» tiene que ser mayor que cero.");
                }

                if ($costo < 0) {
                    throw new RuntimeException("El costo de «{$producto->nombre}» no puede ser negativo.");
                }

                CompraDetalle::create([
                    'compra_id' => $compra->id,
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'costo_unitario' => $costo,
                ]);

                Inventario::mover($producto->id, $cantidad, 'ENTRADA', 'COMPRA', $usuario->id, [
                    'compra_id' => $compra->id,
                    'costo_unitario' => $costo,
                ]);

                // Una caja regalada (costo 0) no es el costo del producto: si lo
                // fuera, todas las ventas siguientes se congelarían con 100 % de
                // ganancia hasta la próxima compra.
                if ($costo > 0) {
                    $producto->forceFill(['costo' => $costo])->save();
                }
            }

            Auditor::registrar('COMPRA_REGISTRADA', 'compras', $compra->id, [
                'proveedor' => $proveedor->razon_social,
                'documento' => $compra->documento_externo,
                'lineas' => count($lineas),
                'total' => $compra->fresh('detalle')->total,
            ], $usuario->id);

            return $compra->fresh(['detalle.producto', 'proveedor']);
        });
    }
}
