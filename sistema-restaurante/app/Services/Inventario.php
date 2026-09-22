<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El stock de lo que se compra hecho (las bebidas embotelladas). Es el ÚNICO
 * lugar que lo mueve: cada cambio deja su línea en el kardex con el stock de
 * antes y el de después, y se hace con la fila del producto bloqueada, así
 * dos ventas simultáneas de la misma gaseosa no se pisan el número.
 *
 * Corre en PHP en las dos vías (con la lógica en la base y sin ella), dentro
 * de la transacción de quien lo llama: si la venta o la compra fallan, el
 * stock tampoco se movió.
 *
 * Vender sin stock SE PUEDE: el mostrador avisa, pero no frena una venta en
 * hora pico porque falte registrar una compra. El stock queda negativo y se
 * ve en rojo hasta que se corrige (una compra o la toma de inventario).
 */
class Inventario
{
    // ------------------------------------------------------------- ventas

    /**
     * Descuenta lo vendido. Solo las líneas de productos que controlan stock;
     * los platos no se tocan. En orden de producto: dos ventas con las mismas
     * bebidas bloquean las filas en el mismo orden y no se traban entre sí.
     */
    public static function salidaPorVenta(Venta $venta, Usuario $usuario): void
    {
        $lineas = $venta->detalle()
            ->join('productos as p', 'p.id', '=', 'venta_detalle.producto_id')
            ->where('p.controla_stock', 1)
            ->orderBy('venta_detalle.producto_id')
            ->get(['venta_detalle.producto_id', 'venta_detalle.cantidad', 'venta_detalle.costo_unitario']);

        foreach ($lineas as $linea) {
            self::mover((int) $linea->producto_id, (float) $linea->cantidad, 'SALIDA', 'VENTA', $usuario->id, [
                'venta_id' => $venta->id,
                'costo_unitario' => $linea->costo_unitario,
            ]);
        }
    }

    /**
     * Devuelve al stock EXACTAMENTE lo que la venta sacó, leído del kardex: si
     * el producto dejó de controlar stock después, igual vuelve lo que salió.
     */
    public static function reponerVenta(Venta $venta, Usuario $usuario): void
    {
        $salidas = MovimientoInventario::where('venta_id', $venta->id)
            ->where('origen', 'VENTA')
            ->orderBy('producto_id')
            ->get();

        foreach ($salidas as $salida) {
            self::mover($salida->producto_id, (float) $salida->cantidad, 'ENTRADA', 'ANULACION', $usuario->id, [
                'venta_id' => $venta->id,
                'costo_unitario' => $salida->costo_unitario,
                'motivo' => 'Anulación de la venta',
            ]);
        }
    }

    // ------------------------------------------------------ el resto del kardex

    /** El stock con el que empieza a controlarse un producto. */
    public static function inicial(Producto $producto, float $cantidad, Usuario $usuario): ?MovimientoInventario
    {
        if ($cantidad <= 0) {
            return null;
        }

        return self::mover($producto->id, $cantidad, 'ENTRADA', 'INICIAL', $usuario->id, [
            'costo_unitario' => $producto->costo,
            'motivo' => 'Stock inicial',
        ]);
    }

    /**
     * Deja el stock en lo contado (una rotura, un conteo rápido). Si hay una
     * toma abierta, el conteo de ese producto se olvida: si no, el cierre de
     * la toma aplicaría otra vez la misma diferencia.
     */
    public static function ajuste(Producto $producto, float $contado, string $motivo, Usuario $usuario): ?MovimientoInventario
    {
        if ($contado < 0) {
            throw new RuntimeException('El stock contado no puede ser negativo.');
        }

        if (blank($motivo)) {
            throw new RuntimeException('Un ajuste necesita el motivo: sin él, es un descuadre sin explicación.');
        }

        // Un plato no tiene stock: un ajuste le creaba movimientos, y al
        // activarle el inventario ya no se pedía el stock inicial.
        if (! $producto->controla_stock) {
            throw new RuntimeException("«{$producto->nombre}» no lleva inventario.");
        }

        return DB::transaction(function () use ($producto, $contado, $motivo, $usuario) {
            $actual = self::bloquear($producto->id);
            $diferencia = round($contado - $actual, 3);

            if ($diferencia === 0.0) {
                return null;
            }

            $movimiento = self::registrar($producto->id, abs($diferencia), $diferencia > 0 ? 'ENTRADA' : 'SALIDA', 'AJUSTE',
                $actual, $contado, $usuario->id, ['motivo' => mb_substr($motivo, 0, 255), 'costo_unitario' => $producto->costo]);

            TomasInventario::olvidarConteoDe($producto);

            return $movimiento;
        });
    }

    /**
     * Un movimiento con origen y referencias. Lo usan Compras,
     * DevolucionesCompra y TomasInventario; nadie más escribe el kardex.
     *
     * @param  array<string, mixed>  $extra  referencias (compra_id, ...), costo_unitario, motivo
     */
    public static function mover(int $productoId, float $cantidad, string $tipo, string $origen, int $usuarioId, array $extra = []): MovimientoInventario
    {
        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad de un movimiento de inventario debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($productoId, $cantidad, $tipo, $origen, $usuarioId, $extra) {
            $anterior = self::bloquear($productoId);
            $resultante = round($tipo === 'SALIDA' ? $anterior - $cantidad : $anterior + $cantidad, 3);

            return self::registrar($productoId, $cantidad, $tipo, $origen, $anterior, $resultante, $usuarioId, $extra);
        });
    }

    /** El stock de ahora, con la fila del producto bloqueada hasta el fin de la transacción. */
    public static function bloquear(int $productoId): float
    {
        $stock = Producto::whereKey($productoId)->lockForUpdate()->value('stock_actual');

        if ($stock === null) {
            throw new RuntimeException('El producto no existe.');
        }

        return (float) $stock;
    }

    /** @param  array<string, mixed>  $extra */
    private static function registrar(
        int $productoId,
        float $cantidad,
        string $tipo,
        string $origen,
        float $anterior,
        float $resultante,
        int $usuarioId,
        array $extra = [],
    ): MovimientoInventario {
        Producto::whereKey($productoId)->update(['stock_actual' => $resultante]);

        return MovimientoInventario::create([
            ...$extra,
            'producto_id' => $productoId,
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'origen' => $origen,
            'cantidad' => $cantidad,
            'stock_anterior' => $anterior,
            'stock_resultante' => $resultante,
            'fecha' => now(),
        ]);
    }
}
