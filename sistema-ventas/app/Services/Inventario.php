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

        // El stock y su lote juntos: si el lote no se puede guardar, tampoco
        // queda el movimiento.
        return DB::transaction(function () use ($producto, $cantidad, $detalle, $vence) {
            $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'INICIAL', [
                'motivo' => 'Carga inicial de inventario'.($detalle ? " ({$detalle})" : ''),
                'costo_unitario' => (float) $producto->precio_compra,
            ]);

            Lotes::ingresar($producto, $cantidad, $vence);

            return $movimiento;
        }, self::REINTENTOS);
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
        ?int $usuarioId = null,
    ): MovimientoInventario {
        // El stock y su lote juntos: antes el movimiento se confirmaba en su
        // propia transacción y, si el lote fallaba, quedaba stock sin tanda.
        return DB::transaction(function () use ($producto, $cantidad, $proveedorId, $documentoExterno, $costoUnitario, $motivo, $compraId, $vence, $lote, $compraDetalleId, $usuarioId) {
            $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'COMPRA', [
                'usuario_id' => $usuarioId ?? Auth::id(),
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
        }, self::REINTENTOS);
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
        ?int $usuarioId = null,
    ): MovimientoInventario {
        return DB::transaction(function () use ($producto, $cantidad, $devolucionCompraId, $proveedorId, $documentoExterno, $costoUnitario, $motivo, $lote, $usuarioId) {
            $movimiento = self::mover($producto, $cantidad, 'SALIDA', 'DEVOLUCION_COMPRA', [
                'usuario_id' => $usuarioId ?? Auth::id(),
                'devolucion_compra_id' => $devolucionCompraId,
                'proveedor_id' => $proveedorId,
                'documento_externo' => $documentoExterno,
                'costo_unitario' => $costoUnitario,
                'motivo' => $motivo,
            ]);

            Lotes::consumirDe($producto, $cantidad, $lote);

            return $movimiento;
        }, self::REINTENTOS);
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
        ?int $usuarioId = null,
    ): MovimientoInventario {
        return DB::transaction(function () use ($producto, $cantidad, $devolucionCompraId, $proveedorId, $documentoExterno, $costoUnitario, $vence, $usuarioId) {
            $movimiento = self::mover($producto, $cantidad, 'ENTRADA', 'DEVOLUCION_COMPRA', [
                'usuario_id' => $usuarioId ?? Auth::id(),
                'devolucion_compra_id' => $devolucionCompraId,
                'proveedor_id' => $proveedorId,
                'documento_externo' => $documentoExterno,
                'costo_unitario' => $costoUnitario,
                'motivo' => 'Reposición del proveedor',
            ]);

            Lotes::ingresar($producto, $cantidad, $vence);

            return $movimiento;
        }, self::REINTENTOS);
    }

    /**
     * Da de baja una tanda entera: lo vencido deja de contar como stock.
     *
     * Es un AJUSTE, igual que un conteo físico, y por el mismo motivo: la
     * mercadería no se vendió ni volvió al proveedor, simplemente dejó de
     * existir. Lo que cambia es quién hace la resta. El ajuste normal pide el
     * stock contado y obliga a calcular a mano «24 en total menos 4 vencidas =
     * 20», que es justo donde alguien escribe 0 y se lleva por delante las 20
     * buenas. Aquí la cantidad sale de la propia tanda.
     *
     * Y sale de ESA tanda, no de la que tocaría por orden de salida: se está
     * dando de baja un lote concreto porque venció, no descontando a ciegas.
     */
    public static function bajaDeLote(Lote $lote, string $motivo): ?MovimientoInventario
    {
        $producto = $lote->producto;

        // Con el control apagado los lotes no siguen al stock: la baja restaba
        // del stock pero no del lote, y la misma tanda se podía dar de baja una
        // y otra vez hasta dejar el producto en cero.
        if (! $producto?->controla_vencimiento) {
            throw new RuntimeException("«{$producto?->nombre}» no controla vencimiento: sus lotes ya no se dan de baja. Si hay mercadería vencida, regístrala como ajuste de inventario.");
        }

        return DB::transaction(function () use ($lote, $producto, $motivo) {
            $actual = (float) Producto::whereKey($producto->id)->lockForUpdate()->value('stock_actual');

            // Se relee dentro del bloqueo: entre que se pintó la pantalla y se
            // confirmó, el mostrador pudo haber vendido parte de esa tanda.
            $lote->refresh();
            $cantidad = min((float) $lote->cantidad_actual, $actual);

            if ($cantidad <= 0) {
                return null;
            }

            $movimiento = self::registrar(
                producto: $producto,
                cantidad: $cantidad,
                tipo: 'AJUSTE',
                origen: 'AJUSTE',
                stockAnterior: $actual,
                stockResultante: round($actual - $cantidad, 3),
                extra: ['motivo' => mb_substr($motivo, 0, 255)],
            );

            Lotes::consumirDe($producto, $cantidad, $lote);

            // Lo mismo que el ajuste: el conteo de una toma abierta ya no vale.
            TomasInventario::olvidarConteoDe($producto);

            return $movimiento;
        }, self::REINTENTOS);
    }

    /**
     * Ajuste por conteo físico: se indica el stock real y el sistema calcula
     * la diferencia. El motivo es obligatorio; un descuadre sin explicación no
     * sirve de nada.
     */
    public static function ajuste(Producto $producto, float $stockContado, string $motivo): ?MovimientoInventario
    {
        return DB::transaction(function () use ($producto, $stockContado, $motivo) {
            $movimiento = self::ajustarA($producto, fn () => $stockContado, $motivo);

            // Si el producto ya se contó en una toma abierta, ese conteo quedó
            // viejo: el cierre aplicaría la diferencia por segunda vez.
            if ($movimiento) {
                TomasInventario::olvidarConteoDe($producto);
            }

            return $movimiento;
        }, self::REINTENTOS);
    }

    /**
     * Corrección por una toma de inventario: se aplica la DIFERENCIA que se
     * contó sobre el stock de ahora, no un stock absoluto.
     *
     * Entre que se contó el estante y se cerró la toma el local siguió
     * vendiendo. Si se contaron 10 cuando el sistema decía 12 y después se
     * vendieron 3, lo que hay es 7: 12 − 3 − 2. Escribir el 10 contado
     * borraría esas tres ventas del stock.
     *
     * Nunca deja stock negativo: si lo vendido después del conteo ya se llevó
     * más de lo que había, el producto queda en cero.
     * Ver {@see TomasInventario}.
     */
    public static function corregir(Producto $producto, float $diferencia, string $motivo, ?int $usuarioId = null): ?MovimientoInventario
    {
        return self::ajustarA($producto, fn (float $actual) => max(round($actual + $diferencia, 3), 0.0), $motivo, $usuarioId);
    }

    /**
     * El ajuste en sí: bloquea el producto, calcula el stock al que tiene que
     * quedar a partir del de AHORA y registra la diferencia.
     *
     * @param  callable(float): float  $destino
     */
    private static function ajustarA(Producto $producto, callable $destino, string $motivo, ?int $usuarioId = null): ?MovimientoInventario
    {
        return DB::transaction(function () use ($producto, $destino, $motivo, $usuarioId) {
            $actual = (float) Producto::whereKey($producto->id)->lockForUpdate()->value('stock_actual');
            $stockContado = (float) $destino($actual);
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
                extra: ['motivo' => $motivo, 'usuario_id' => $usuarioId ?? Auth::id()],
            );

            // Un conteo que corrige hacia abajo se descuenta primero de lo YA
            // VENCIDO: es de donde sale la merma, y si se descontaba de lo bueno
            // la pantalla de vencimientos seguía mostrando lo que ya estaba en
            // la basura —y darlo de baja lo descontaba por segunda vez—. Uno que
            // corrige hacia arriba repone donde estaba. Los lotes siguen al
            // stock, nunca al revés.
            $diferencia < 0
                ? Lotes::consumir($producto, abs($diferencia), vencidoPrimero: true)
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
        // Todo movimiento tiene responsable; solo la carga INICIAL puede no
        // tenerlo. La base lo exige con un trigger; aquí se exige también para
        // el modo sin triggers, y antes de tocar el stock.
        $usuarioId = array_key_exists('usuario_id', $extra) ? $extra['usuario_id'] : Auth::id();

        if ($usuarioId === null && $origen !== 'INICIAL') {
            throw new RuntimeException('El movimiento de inventario necesita un responsable');
        }

        $producto->newQuery()->whereKey($producto->id)->update(['stock_actual' => $stockResultante]);
        $producto->stock_actual = $stockResultante;

        return MovimientoInventario::create([
            'producto_id' => $producto->id,
            'tipo' => $tipo,
            'origen' => $origen,
            'cantidad' => $cantidad,
            'stock_anterior' => $stockAnterior,
            'stock_resultante' => $stockResultante,
            'fecha' => now(),
            ...$extra,
            'usuario_id' => $usuarioId,
        ]);
    }
}
