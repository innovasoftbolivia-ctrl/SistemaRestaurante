<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Único punto por el que cambia `productos.stock_actual`.
 *
 * Cada cambio deja un movimiento en el kardex con el stock antes y después,
 * quién lo hizo y por qué (RNF6). El par «actualizar stock + registrar
 * movimiento» va en una transacción con bloqueo de la fila del producto, para
 * que dos ingresos simultáneos no se pisen (RNF5).
 */
class Inventario
{
    /** Reintentos ante un deadlock; mismo criterio que `Ventas::REINTENTOS`. */
    private const REINTENTOS = 3;

    /**
     * Carga inicial de stock al dar de alta el producto.
     *
     * `$detalle` es cómo se contó esa carga cuando se hizo por empaques —«5
     * cajas de 24»—. Se guarda junto al motivo porque el número solo no
     * permite después contrastar el alta con lo que había físicamente.
     */
    public static function cargaInicial(Producto $producto, float $cantidad, ?string $detalle = null): ?MovimientoInventario
    {
        if ($cantidad <= 0) {
            return null;
        }

        return self::mover($producto, $cantidad, 'ENTRADA', 'INICIAL', [
            'motivo' => 'Carga inicial de inventario'.($detalle ? " ({$detalle})" : ''),
            'costo_unitario' => (float) $producto->precio_compra,
        ]);
    }

    /** Mercadería que llega del proveedor. */
    public static function ingreso(
        Producto $producto,
        float $cantidad,
        ?int $proveedorId = null,
        ?string $documentoExterno = null,
        ?float $costoUnitario = null,
        ?string $motivo = null,
    ): MovimientoInventario {
        return self::mover($producto, $cantidad, 'ENTRADA', 'COMPRA', [
            'proveedor_id' => $proveedorId,
            'documento_externo' => $documentoExterno,
            'costo_unitario' => $costoUnitario,
            'motivo' => $motivo,
        ]);
    }

    /**
     * Ajuste por conteo físico: se indica el stock real y el sistema calcula
     * la diferencia. El motivo es obligatorio; un descuadre sin explicación no
     * sirve de nada.
     */
    public static function ajuste(Producto $producto, float $stockContado, string $motivo): ?MovimientoInventario
    {
        return DB::transaction(function () use ($producto, $stockContado, $motivo) {
            $actual = (float) Producto::whereKey($producto->id)->lockForUpdate()->value('stock_actual');
            $diferencia = round($stockContado - $actual, 3);

            if ($diferencia === 0.0) {
                return null;
            }

            return self::registrar(
                producto: $producto,
                cantidad: abs($diferencia),
                tipo: 'AJUSTE',
                origen: 'AJUSTE',
                stockAnterior: $actual,
                stockResultante: $stockContado,
                extra: ['motivo' => $motivo],
            );
        }, self::REINTENTOS);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function mover(
        Producto $producto,
        float $cantidad,
        string $tipo,
        string $origen,
        array $extra = [],
    ): MovimientoInventario {
        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad de un movimiento de inventario debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($producto, $cantidad, $tipo, $origen, $extra) {
            $anterior = (float) Producto::whereKey($producto->id)->lockForUpdate()->value('stock_actual');
            $resultante = $tipo === 'SALIDA'
                ? round($anterior - $cantidad, 3)
                : round($anterior + $cantidad, 3);

            if ($resultante < 0) {
                throw new RuntimeException("No hay stock suficiente de «{$producto->nombre}».");
            }

            return self::registrar($producto, $cantidad, $tipo, $origen, $anterior, $resultante, $extra);
        }, self::REINTENTOS);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function registrar(
        Producto $producto,
        float $cantidad,
        string $tipo,
        string $origen,
        float $stockAnterior,
        float $stockResultante,
        array $extra = [],
    ): MovimientoInventario {
        $producto->newQuery()->whereKey($producto->id)->update(['stock_actual' => $stockResultante]);
        $producto->stock_actual = $stockResultante;

        return MovimientoInventario::create([
            'producto_id' => $producto->id,
            'usuario_id' => Auth::id(),
            'tipo' => $tipo,
            'origen' => $origen,
            'cantidad' => $cantidad,
            'stock_anterior' => $stockAnterior,
            'stock_resultante' => $stockResultante,
            'fecha' => now(),
            ...$extra,
        ]);
    }
}
