<?php

namespace App\Services;

use App\Models\Lote;
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
    public static function cargaInicial(
        Producto $producto,
        float $cantidad,
        ?string $detalle = null,
        ?string $vence = null,
    ): ?MovimientoInventario {
        if ($cantidad <= 0) {
            return null;
        }

        $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'INICIAL', [
            'motivo' => 'Carga inicial de inventario'.($detalle ? " ({$detalle})" : ''),
            'costo_unitario' => (float) $producto->precio_compra,
        ]);

        Lotes::ingresar($producto, $cantidad, $vence);

        return $movimiento;
    }

    /**
     * Mercadería que llega del proveedor.
     *
     * `$compraId` engancha el movimiento a la cabecera de una compra cuando la
     * entrada vino de una factura con varias líneas. Es opcional porque el
     * almacén también carga de a una —llegó una caja suelta, no hay documento
     * que abrir— y esa vía tenía que seguir funcionando igual: el esquema
     * guarda `proveedor_id` y `documento_externo` en el propio movimiento
     * justo para eso.
     */
    public static function ingreso(
        Producto $producto,
        float $cantidad,
        ?int $proveedorId = null,
        ?string $documentoExterno = null,
        ?float $costoUnitario = null,
        ?string $motivo = null,
        ?int $compraId = null,
        ?string $vence = null,
        ?string $lote = null,
        ?int $compraDetalleId = null,
    ): MovimientoInventario {
        $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'COMPRA', [
            'proveedor_id' => $proveedorId,
            'documento_externo' => $documentoExterno,
            'compra_id' => $compraId,
            'costo_unitario' => $costoUnitario,
            'motivo' => $motivo,
        ]);

        // La mercadería que entra abre su tanda con la fecha que trae la caja.
        // Si el producto no lleva control de vencimiento, esto no hace nada.
        Lotes::ingresar($producto, $cantidad, $vence, $lote, $compraDetalleId);

        return $movimiento;
    }

    /**
     * Mercadería que se le devuelve al proveedor.
     *
     * Es una SALIDA con origen propio, y no un ajuste: un ajuste explica un
     * descuadre —merma, rotura, conteo— y esto explica que algo se fue de
     * vuelta por donde vino. Mezclarlos haría imposible después contar cuánto
     * se devolvió y por qué.
     *
     * `$lote` es la tanda concreta que se devuelve, cuando se eligió una: lo
     * vencido se devuelve de SU lote y no del que tocaría por orden de salida.
     */
    public static function salidaAProveedor(
        Producto $producto,
        float $cantidad,
        int $devolucionCompraId,
        ?int $proveedorId = null,
        ?string $documentoExterno = null,
        ?float $costoUnitario = null,
        ?string $motivo = null,
        ?Lote $lote = null,
    ): MovimientoInventario {
        $movimiento = self::mover($producto, $cantidad, 'SALIDA', 'DEVOLUCION_COMPRA', [
            'devolucion_compra_id' => $devolucionCompraId,
            'proveedor_id' => $proveedorId,
            'documento_externo' => $documentoExterno,
            'costo_unitario' => $costoUnitario,
            'motivo' => $motivo,
        ]);

        Lotes::consumirDe($producto, $cantidad, $lote);

        return $movimiento;
    }

    /**
     * Lo que el proveedor repone a cambio de lo devuelto.
     *
     * Entra con el mismo documento que la salida —es el otro lado del cambio—
     * y abre su propia tanda: el reemplazo de algo vencido viene, por
     * definición, con una fecha distinta.
     */
    public static function entradaPorReposicion(
        Producto $producto,
        float $cantidad,
        int $devolucionCompraId,
        ?int $proveedorId = null,
        ?string $documentoExterno = null,
        ?float $costoUnitario = null,
        ?string $vence = null,
    ): MovimientoInventario {
        $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'DEVOLUCION_COMPRA', [
            'devolucion_compra_id' => $devolucionCompraId,
            'proveedor_id' => $proveedorId,
            'documento_externo' => $documentoExterno,
            'costo_unitario' => $costoUnitario,
            'motivo' => 'Reposición del proveedor',
        ]);

        Lotes::ingresar($producto, $cantidad, $vence);

        return $movimiento;
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

            $movimiento = self::registrar(
                producto: $producto,
                cantidad: abs($diferencia),
                tipo: 'AJUSTE',
                origen: 'AJUSTE',
                stockAnterior: $actual,
                stockResultante: $stockContado,
                extra: ['motivo' => $motivo],
            );

            // Un conteo que corrige hacia abajo se descuenta de lo que vence
            // antes —la merma y la rotura suelen salir justo de ahí—, y uno
            // que corrige hacia arriba repone donde estaba. Los lotes siguen
            // al stock, nunca al revés.
            $diferencia < 0
                ? Lotes::consumir($producto, abs($diferencia))
                : Lotes::reponer($producto, $diferencia);

            return $movimiento;
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
