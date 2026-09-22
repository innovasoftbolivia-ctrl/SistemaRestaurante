<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contar la bodega de una vez y ajustar lo que no cuadra.
 *
 * El local sigue vendiendo mientras se cuenta. Por eso cada conteo guarda lo
 * que decía el sistema EN ESE MOMENTO, y el cierre aplica la DIFERENCIA, no
 * el número contado: si se contaron 10 a las 9:00 y a las 11:00 se vendieron
 * 2, el cierre deja 8, no 10.
 */
class TomasInventario
{
    public static function abierta(): ?TomaInventario
    {
        return TomaInventario::where('estado', 'ABIERTA')->first();
    }

    /** Abre la toma con la lista de todo lo que lleva inventario. */
    public static function abrir(Usuario $usuario, ?string $observacion = null): TomaInventario
    {
        if (self::abierta()) {
            throw new RuntimeException('Ya hay una toma de inventario abierta: ciérrala o cancélala antes de abrir otra.');
        }

        $productos = Producto::conStock()->activos()->orderBy('nombre')->pluck('id');

        if ($productos->isEmpty()) {
            throw new RuntimeException('No hay productos que lleven inventario. Actívalo en la ficha de cada bebida del menú.');
        }

        try {
            return DB::transaction(function () use ($usuario, $observacion, $productos) {
                $toma = TomaInventario::create([
                    'estado' => 'ABIERTA',
                    'observacion' => $observacion ?: null,
                    'usuario_apertura_id' => $usuario->id,
                    'fecha_apertura' => now(),
                ]);

                foreach ($productos as $id) {
                    TomaInventarioDetalle::create(['toma_id' => $toma->id, 'producto_id' => $id]);
                }

                Auditor::registrar('TOMA_ABIERTA', 'tomas_inventario', $toma->id, ['productos' => $productos->count()], $usuario->id);

                return $toma;
            });
        } catch (QueryException $e) {
            // El índice único de la base: otra toma se abrió en el mismo instante.
            if (str_contains($e->getMessage(), 'uq_tomas_una_abierta')) {
                throw new RuntimeException('Ya hay una toma de inventario abierta.');
            }
            throw $e;
        }
    }

    /** Anota lo contado de un producto, con lo que decía el sistema en ese momento. */
    public static function contar(TomaInventario $toma, int $productoId, float $contado, Usuario $usuario): TomaInventarioDetalle
    {
        if (! $toma->estaAbierta()) {
            throw new RuntimeException('Esta toma ya no está abierta.');
        }

        if ($contado < 0) {
            throw new RuntimeException('Lo contado no puede ser negativo.');
        }

        return DB::transaction(function () use ($toma, $productoId, $contado, $usuario) {
            $linea = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $productoId)->lockForUpdate()->first();

            if (! $linea) {
                throw new RuntimeException('Ese producto no está en esta toma.');
            }

            $sistema = Inventario::bloquear($productoId);

            $linea->update([
                'contado' => round($contado, 3),
                'stock_sistema' => $sistema,
                'costo_unitario' => Producto::whereKey($productoId)->value('costo'),
                'usuario_id' => $usuario->id,
                'fecha_conteo' => now(),
            ]);

            return $linea->fresh();
        });
    }

    /** Aplica todas las diferencias contadas como movimientos TOMA del kardex. */
    public static function cerrar(TomaInventario $toma, Usuario $usuario): int
    {
        return DB::transaction(function () use ($toma, $usuario) {
            $toma = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();

            if (! $toma->estaAbierta()) {
                throw new RuntimeException('Esta toma ya no está abierta.');
            }

            $ajustes = 0;

            $lineas = $toma->detalle()->whereNotNull('contado')->orderBy('producto_id')->get();

            foreach ($lineas as $linea) {
                $diferencia = round((float) $linea->diferencia, 3);

                if ($diferencia === 0.0) {
                    continue;
                }

                $movimiento = Inventario::mover($linea->producto_id, abs($diferencia), $diferencia > 0 ? 'ENTRADA' : 'SALIDA', 'TOMA', $usuario->id, [
                    'toma_id' => $toma->id,
                    'costo_unitario' => $linea->costo_unitario,
                    'motivo' => $diferencia > 0 ? 'Sobrante en la toma de inventario' : 'Faltante en la toma de inventario',
                ]);

                $linea->update(['movimiento_id' => $movimiento->id]);
                $ajustes++;
            }

            $toma->update(['estado' => 'CERRADA', 'usuario_cierre_id' => $usuario->id, 'fecha_cierre' => now()]);

            Auditor::registrar('TOMA_CERRADA', 'tomas_inventario', $toma->id, [
                'contados' => $lineas->count(),
                'ajustes' => $ajustes,
            ], $usuario->id);

            return $ajustes;
        });
    }

    public static function cancelar(TomaInventario $toma, Usuario $usuario): void
    {
        if (! $toma->estaAbierta()) {
            throw new RuntimeException('Esta toma ya no está abierta.');
        }

        $toma->update(['estado' => 'CANCELADA', 'usuario_cierre_id' => $usuario->id, 'fecha_cierre' => now()]);

        Auditor::registrar('TOMA_CANCELADA', 'tomas_inventario', $toma->id, [], $usuario->id);
    }

    /**
     * Un ajuste manual durante una toma abierta: el conteo de ese producto
     * ya no vale (el cierre aplicaría la misma diferencia otra vez).
     */
    public static function olvidarConteoDe(Producto $producto): void
    {
        TomaInventarioDetalle::where('producto_id', $producto->id)
            ->whereHas('toma', fn ($q) => $q->where('estado', 'ABIERTA'))
            ->update(['contado' => null, 'stock_sistema' => null, 'usuario_id' => null, 'fecha_conteo' => null]);
    }
}
