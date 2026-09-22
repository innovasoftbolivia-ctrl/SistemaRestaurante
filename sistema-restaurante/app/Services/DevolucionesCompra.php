<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\DevolucionCompra;
use App\Models\DevolucionCompraDetalle;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lo que se le devuelve al proveedor, contra la compra en que llegó.
 *
 * Tres finales (`espera`):
 *   REPUESTO      lo cambió en el acto: sale lo fallado y entra lo bueno en
 *                 el mismo documento. El stock queda igual, pero registrado.
 *   PENDIENTE     se lo llevó y traerá el reemplazo: el stock baja hoy y
 *                 sube cuando llegue (`reponer`). Mientras, el proveedor debe.
 *   NOTA_CREDITO  no repone: queda a cuenta.
 */
class DevolucionesCompra
{
    /**
     * @param  array<int, array{compra_detalle_id: int, cantidad: float|int|string}>  $lineas
     */
    public static function registrar(
        Compra $compra,
        Usuario $usuario,
        array $lineas,
        string $motivo,
        string $espera,
        ?string $documentoExterno = null,
        ?string $observacion = null,
    ): DevolucionCompra {
        if (! isset(DevolucionCompra::MOTIVOS[$motivo])) {
            throw new RuntimeException('Elige el motivo de la devolución.');
        }

        if (! isset(DevolucionCompra::ESPERAS[$espera])) {
            throw new RuntimeException('Elige en qué queda la devolución con el proveedor.');
        }

        $lineas = array_values(array_filter($lineas, fn ($l) => (float) ($l['cantidad'] ?? 0) > 0));

        if ($lineas === []) {
            throw new RuntimeException('La devolución no tiene ninguna cantidad.');
        }

        return DB::transaction(function () use ($compra, $usuario, $lineas, $motivo, $espera, $documentoExterno, $observacion) {
            $devolucion = DevolucionCompra::create([
                'compra_id' => $compra->id,
                'usuario_id' => $usuario->id,
                'fecha' => now(),
                'motivo' => $motivo,
                'espera' => $espera,
                'documento_externo' => $documentoExterno ?: null,
                'observacion' => $observacion ?: null,
            ]);

            usort($lineas, fn ($a, $b) => (int) $a['compra_detalle_id'] <=> (int) $b['compra_detalle_id']);

            foreach ($lineas as $linea) {
                // La línea bloqueada: dos devoluciones a la vez no pueden
                // devolver entre las dos más de lo que llegó.
                $original = CompraDetalle::whereKey((int) $linea['compra_detalle_id'])
                    ->where('compra_id', $compra->id)
                    ->lockForUpdate()
                    ->first();

                if (! $original) {
                    throw new RuntimeException('Esa línea no es de esta compra.');
                }

                $cantidad = round((float) $linea['cantidad'], 3);
                $nombre = $original->producto?->nombre ?? 'el producto';

                if ($cantidad > $original->devolvible) {
                    throw new RuntimeException("De «{$nombre}» se pueden devolver hasta ".Config::cantidad($original->devolvible).' unidades.');
                }

                // Lo que se entrega al proveedor tiene que estar en la bodega: si
                // ya se vendió, devolverlo es un error de carga que dejaba el stock
                // en negativo. (Cambiado en el acto no mueve el stock.)
                if ($espera !== 'REPUESTO') {
                    $hay = Inventario::bloquear($original->producto_id);
                    if ($cantidad > $hay) {
                        throw new RuntimeException("De «{$nombre}» hay ".Config::cantidad(max(0, $hay)).' en stock: no se puede devolver más de lo que hay.');
                    }
                }

                DevolucionCompraDetalle::create([
                    'devolucion_compra_id' => $devolucion->id,
                    'compra_detalle_id' => $original->id,
                    'producto_id' => $original->producto_id,
                    'cantidad' => $cantidad,
                    'cantidad_repuesta' => $espera === 'REPUESTO' ? $cantidad : 0,
                    'costo_unitario' => $original->costo_unitario,
                ]);

                // Lo cambiado en el acto no cuenta como devuelto: el reemplazo es
                // mercadería nueva de esa misma línea, y si también viene mala se
                // tiene que poder devolver.
                if ($espera !== 'REPUESTO') {
                    $original->increment('cantidad_devuelta', $cantidad);
                }

                Inventario::mover($original->producto_id, $cantidad, 'SALIDA', 'DEVOLUCION_COMPRA', $usuario->id, [
                    'devolucion_compra_id' => $devolucion->id,
                    'costo_unitario' => $original->costo_unitario,
                    'motivo' => DevolucionCompra::MOTIVOS[$motivo],
                ]);

                if ($espera === 'REPUESTO') {
                    Inventario::mover($original->producto_id, $cantidad, 'ENTRADA', 'REPOSICION', $usuario->id, [
                        'devolucion_compra_id' => $devolucion->id,
                        'costo_unitario' => $original->costo_unitario,
                        'motivo' => 'Cambiado en el momento',
                    ]);
                }
            }

            Auditor::registrar('DEVOLUCION_COMPRA', 'devoluciones_compra', $devolucion->id, [
                'compra_id' => $compra->id,
                'motivo' => $motivo,
                'espera' => $espera,
                'total' => $devolucion->fresh('detalle')->total,
            ], $usuario->id);

            return $devolucion->fresh(['detalle.producto', 'compra.proveedor']);
        });
    }

    /**
     * Llegó lo que el proveedor debía (toda o una parte).
     *
     * @param  array<int, float|int|string>  $cantidades  id de la línea de la devolución => cantidad que llegó
     */
    public static function reponer(DevolucionCompra $devolucion, Usuario $usuario, array $cantidades): int
    {
        if ($devolucion->espera !== 'PENDIENTE') {
            throw new RuntimeException('Solo se registra la reposición de una devolución que quedó pendiente.');
        }

        return DB::transaction(function () use ($devolucion, $usuario, $cantidades) {
            $repuestas = 0;

            foreach ($devolucion->detalle()->orderBy('id')->lockForUpdate()->get() as $linea) {
                $cantidad = round((float) ($cantidades[$linea->id] ?? 0), 3);

                if ($cantidad <= 0) {
                    continue;
                }

                if ($cantidad > $linea->por_reponer) {
                    throw new RuntimeException("De «{$linea->producto?->nombre}» el proveedor debe ".Config::cantidad($linea->por_reponer).' unidades.');
                }

                $linea->increment('cantidad_repuesta', $cantidad);
                // Lo repuesto vuelve a ser devolvible, igual que lo cambiado en
                // el acto: el reemplazo también puede venir malo.
                CompraDetalle::whereKey($linea->compra_detalle_id)->decrement('cantidad_devuelta', $cantidad);

                Inventario::mover($linea->producto_id, $cantidad, 'ENTRADA', 'REPOSICION', $usuario->id, [
                    'devolucion_compra_id' => $devolucion->id,
                    'costo_unitario' => $linea->costo_unitario,
                    'motivo' => 'Reposición del proveedor',
                ]);

                $repuestas++;
            }

            if ($repuestas === 0) {
                throw new RuntimeException('Escribe cuánto llegó de al menos un producto.');
            }

            Auditor::registrar('REPOSICION_PROVEEDOR', 'devoluciones_compra', $devolucion->id, [
                'cantidades' => array_filter($cantidades, fn ($c) => (float) $c > 0),
            ], $usuario->id);

            return $repuestas;
        });
    }
}
