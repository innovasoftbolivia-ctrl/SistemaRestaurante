<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\DevolucionCompra;
use App\Models\DevolucionCompraDetalle;
use App\Models\Lote;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mercadería que vuelve al proveedor: vino fallada, vino equivocada o se venció.
 *
 * Hasta aquí la única salida era un ajuste de inventario con el motivo escrito
 * a mano. Bajaba el stock, sí, pero quedaba mezclado con la merma y la rotura,
 * no se sabía de qué factura había salido, y nadie podía responder después
 * «cuánto le devolví a este proveedor» ni «cuánto perdí por vencimiento».
 *
 * Dos cosas que conviene tener claras de cómo está hecho:
 *
 * El stock sigue moviéndose por {@see Inventario}. Esto no abre una tercera
 * puerta: registra el documento y pide la salida, igual que {@see Compras}
 * pide la entrada.
 *
 * Y el cambio no es otro módulo. Es esta misma devolución con `con_reposicion`:
 * sale lo fallado y entra lo repuesto, los dos movimientos colgados del mismo
 * documento. El stock termina como estaba, que es exactamente lo que pasó en el
 * mostrador — pero queda escrito que hubo un problema, que es lo que un ajuste
 * a cero nunca podría contar.
 */
class DevolucionesCompra
{
    /** Reintentos ante un deadlock; mismo criterio que {@see Ventas}. */
    private const REINTENTOS = 3;

    /**
     * @param  array<int, array{
     *     compra_detalle_id: int,
     *     cantidad: float,
     *     lote_id?: ?int,
     *     vence_repuesto?: ?string
     * }>  $lineas
     */
    public static function registrar(
        Usuario $usuario,
        Compra $compra,
        array $lineas,
        string $motivo,
        bool $conReposicion = false,
        ?string $documentoExterno = null,
        ?string $observacion = null,
    ): DevolucionCompra {
        if ($lineas === []) {
            throw new RuntimeException('La devolución no tiene ninguna línea.');
        }

        if (! in_array($motivo, DevolucionCompra::MOTIVOS, true)) {
            throw new RuntimeException('Ese motivo de devolución no existe.');
        }

        return DB::transaction(function () use ($usuario, $compra, $lineas, $motivo, $conReposicion, $documentoExterno, $observacion) {
            $devolucion = DevolucionCompra::create([
                'compra_id' => $compra->id,
                'usuario_id' => $usuario->id,
                'fecha' => now(),
                'motivo' => $motivo,
                'con_reposicion' => $conReposicion,
                'documento_externo' => $documentoExterno,
                'observacion' => $observacion,
            ]);

            foreach ($lineas as $linea) {
                self::agregarLinea($devolucion, $compra, $linea);
            }

            Auditor::registrar('DEVOLUCION_COMPRA_REGISTRADA', 'devoluciones_compra', $devolucion->id, [
                'compra' => $compra->documento_externo ?: "#{$compra->id}",
                'motivo' => $motivo,
                'con_reposicion' => $conReposicion,
                'lineas' => count($lineas),
                'total' => $devolucion->fresh('detalle')->total,
            ], $usuario->id);

            return $devolucion->fresh(['detalle.producto', 'compra.proveedor']);
        }, self::REINTENTOS);
    }

    /**
     * Una línea: sale la mercadería y, si hay cambio, vuelve a entrar.
     *
     * @param  array<string, mixed>  $linea
     */
    private static function agregarLinea(DevolucionCompra $devolucion, Compra $compra, array $linea): void
    {
        /** @var CompraDetalle $original */
        $original = CompraDetalle::with('producto.unidadMedida')
            ->where('compra_id', $compra->id)
            ->whereKey($linea['compra_detalle_id'])
            ->lockForUpdate()
            ->first();

        if (! $original) {
            throw new RuntimeException('Una de las líneas no pertenece a esta compra.');
        }

        $cantidad = round((float) $linea['cantidad'], 3);
        $producto = $original->producto;

        // No se puede devolver más de lo que trajo esa línea, descontando lo ya
        // devuelto antes. El acumulado vive en la propia línea de compra porque
        // un CHECK no puede consultar otra tabla.
        $pendiente = round((float) $original->cantidad - (float) $original->cantidad_devuelta, 3);

        if ($cantidad <= 0) {
            throw new RuntimeException("La cantidad a devolver de «{$producto->nombre}» debe ser mayor que cero.");
        }

        if ($cantidad > $pendiente) {
            throw new RuntimeException(
                "De «{$producto->nombre}» solo quedan ".Config::cantidad($pendiente).
                ' sin devolver de esa compra.'
            );
        }

        if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw new RuntimeException("«{$producto->nombre}» se devuelve por unidad entera.");
        }

        $lote = self::loteElegido($linea['lote_id'] ?? null, $producto->id);

        DevolucionCompraDetalle::create([
            'devolucion_compra_id' => $devolucion->id,
            'compra_detalle_id' => $original->id,
            'producto_id' => $producto->id,
            'lote_id' => $lote?->id,
            'cantidad' => $cantidad,
            'costo_unitario' => $original->costo_unitario,
        ]);

        $original->forceFill([
            'cantidad_devuelta' => round((float) $original->cantidad_devuelta + $cantidad, 3),
        ])->save();

        // La salida: el stock baja y queda en el kardex con su documento.
        Inventario::salidaAProveedor(
            producto: $producto,
            cantidad: $cantidad,
            devolucionCompraId: $devolucion->id,
            proveedorId: $compra->proveedor_id,
            documentoExterno: $devolucion->documento_externo,
            costoUnitario: (float) $original->costo_unitario,
            motivo: $devolucion->etiqueta_motivo,
            lote: $lote,
        );

        // El cambio: lo repuesto entra de vuelta, con su fecha nueva si la
        // trae. Es lo que distingue «me lo cambiaron» de «me deben una nota de
        // crédito», y por eso los dos movimientos van en el mismo documento.
        if ($devolucion->con_reposicion) {
            Inventario::entradaPorReposicion(
                producto: $producto,
                cantidad: $cantidad,
                devolucionCompraId: $devolucion->id,
                proveedorId: $compra->proveedor_id,
                documentoExterno: $devolucion->documento_externo,
                costoUnitario: (float) $original->costo_unitario,
                vence: $linea['vence_repuesto'] ?? null,
            );
        }
    }

    /**
     * La tanda que se devuelve, cuando se eligió una.
     *
     * Se comprueba que sea de este producto: un `lote_id` de otro producto
     * llegaría a descontar unidades donde no corresponde, y el formulario no es
     * el único que llama a este servicio.
     */
    private static function loteElegido(?int $loteId, int $productoId): ?Lote
    {
        if (! $loteId) {
            return null;
        }

        $lote = Lote::whereKey($loteId)->where('producto_id', $productoId)->first();

        if (! $lote) {
            throw new RuntimeException('Esa tanda no pertenece al producto que se está devolviendo.');
        }

        return $lote;
    }

    /**
     * Lo devuelto a un proveedor en un recorte de fechas, por motivo.
     *
     * Es la cifra que dice si hay que comprarle menos a alguien o rotar mejor
     * la mercadería: «este trimestre devolví Bs 900 por vencimiento» es una
     * conversación distinta a «devolví Bs 900 porque vino fallado».
     */
    public static function porMotivo(?string $desde = null, ?string $hasta = null): Builder
    {
        return DB::table('devoluciones_compra as d')
            ->join('devolucion_compra_detalle as dd', 'dd.devolucion_compra_id', '=', 'd.id')
            ->when($desde, fn ($q) => $q->where('d.fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('d.fecha', '<=', $hasta))
            ->groupBy('d.motivo')
            ->selectRaw('d.motivo, COUNT(DISTINCT d.id) AS documentos, SUM(dd.importe) AS total');
    }
}
