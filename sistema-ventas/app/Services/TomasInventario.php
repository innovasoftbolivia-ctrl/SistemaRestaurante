<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Toma de inventario: contar toda la tienda, o una categoría, de una vez.
 *
 * Tres pasos. Se ABRE con la lista de productos activos del alcance. Se CUENTA
 * de a uno, en el orden que sea y entre varias personas, y cada conteo se
 * guarda al momento. Se CIERRA, y ahí recién se toca el stock: cada diferencia
 * entra al kardex como un ajuste normal, por {@see Inventario}.
 *
 * La regla que sostiene todo: el local no deja de vender mientras se cuenta.
 * Cada línea guarda lo que decía el sistema en el momento de contarla, y el
 * cierre aplica la DIFERENCIA sobre el stock de ese instante. Contados 10
 * cuando el sistema decía 12 (faltan 2) y vendidos 3 después: el cierre deja
 * 12 − 3 − 2 = 7, que es lo que hay en el estante. Aplicar el número contado
 * dejaría 10 y se «comería» las 3 ventas.
 *
 * Lo que no se contó no se toca: no contar un producto no es contar cero.
 */
class TomasInventario
{
    /** Productos activos del alcance, cada uno a la espera de su conteo. */
    public static function abrir(Usuario $usuario, ?int $categoriaId = null, ?string $observacion = null): TomaInventario
    {
        if ($abierta = TomaInventario::where('estado', 'ABIERTA')->first()) {
            throw new RuntimeException("Ya hay una toma de inventario abierta (#{$abierta->id}). Ciérrala o cancélala antes de empezar otra.");
        }

        try {
            return DB::transaction(function () use ($usuario, $categoriaId, $observacion) {
                $toma = TomaInventario::create([
                    'categoria_id' => $categoriaId,
                    'estado' => 'ABIERTA',
                    'observacion' => $observacion ?: null,
                    'usuario_apertura_id' => $usuario->id,
                    'fecha_apertura' => now(),
                ]);

                $productos = Producto::activos()
                    ->when($categoriaId, fn ($q, $id) => $q->where('categoria_id', $id))
                    ->orderBy('id')
                    ->selectRaw('? AS toma_id, id AS producto_id', [$toma->id]);

                $cuantos = DB::table('toma_inventario_detalle')->insertUsing(['toma_id', 'producto_id'], $productos);

                if ($cuantos === 0) {
                    throw new RuntimeException($categoriaId
                        ? 'Esa categoría no tiene productos activos: no hay nada que contar.'
                        : 'No hay productos activos: no hay nada que contar.');
                }

                Auditor::registrar('TOMA_INVENTARIO_ABIERTA', 'tomas_inventario', $toma->id, [
                    'alcance' => $categoriaId ? Categoria::whereKey($categoriaId)->value('nombre') : 'Toda la tienda',
                    'productos' => $cuantos,
                ], $usuario->id);

                return $toma;
            });
        } catch (UniqueConstraintViolationException) {
            // Otra persona abrió una en el mismo instante: el índice único de
            // `abierta` es el que lo decide, no la consulta de arriba.
            throw new RuntimeException('Alguien acaba de abrir otra toma de inventario. Recarga la página y sigue con esa.');
        }
    }

    /**
     * Guarda el conteo de un producto. `null` lo vuelve a dejar sin contar.
     *
     * Contarlo otra vez vuelve a tomar la foto del sistema: lo que vale es lo
     * que había en el estante y en el sistema en el ÚLTIMO conteo.
     */
    public static function contar(TomaInventarioDetalle $linea, Usuario $usuario, ?float $contado): TomaInventarioDetalle
    {
        if ($contado !== null && $contado < 0) {
            throw new RuntimeException('El conteo no puede ser negativo.');
        }

        return DB::transaction(function () use ($linea, $usuario, $contado) {
            // Bloqueo compartido: varios pueden contar a la vez, pero nadie
            // mientras se cierra (el cierre la bloquea en exclusiva).
            $toma = TomaInventario::whereKey($linea->toma_id)->sharedLock()->firstOrFail();
            self::exigirAbierta($toma, 'cambiar un conteo');

            if ($contado === null) {
                $linea->update([
                    'contado' => null, 'stock_sistema' => null, 'costo_unitario' => null,
                    'usuario_id' => null, 'fecha_conteo' => null,
                ]);
            } else {
                $producto = Producto::whereKey($linea->producto_id)->firstOrFail(['stock_actual', 'precio_compra']);

                $linea->update([
                    'contado' => round($contado, 3),
                    'stock_sistema' => $producto->stock_actual,
                    'costo_unitario' => $producto->precio_compra,
                    'usuario_id' => $usuario->id,
                    'fecha_conteo' => now(),
                ]);
            }

            return $linea->refresh();
        });
    }

    /**
     * Aplica las diferencias y cierra la toma. Todo o nada: si un ajuste
     * falla, no queda la mitad del local corregida.
     *
     * @return array{total: int, contados: int, con_diferencia: int, faltante: float, sobrante: float, ajustados: int}
     */
    public static function cerrar(TomaInventario $toma, Usuario $usuario): array
    {
        return DB::transaction(function () use ($toma, $usuario) {
            $bloqueada = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            self::exigirAbierta($bloqueada, 'cerrarla');

            $resumen = self::resumen($bloqueada);

            if ($resumen['contados'] === 0) {
                throw new RuntimeException('No se contó ningún producto. Si no se va a contar, cancela la toma.');
            }

            $motivo = "Toma de inventario #{$bloqueada->id}";
            $ajustados = 0;

            $lineas = TomaInventarioDetalle::where('toma_id', $bloqueada->id)
                ->whereNotNull('contado')
                ->where('diferencia', '<>', 0)
                ->with('producto')
                ->orderBy('id')
                ->get();

            foreach ($lineas as $linea) {
                $movimiento = Inventario::corregir($linea->producto, (float) $linea->diferencia, $motivo);

                if ($movimiento) {
                    $linea->update(['movimiento_id' => $movimiento->id]);
                    $ajustados++;
                }
            }

            $bloqueada->update([
                'estado' => 'CERRADA',
                'usuario_cierre_id' => $usuario->id,
                'fecha_cierre' => now(),
            ]);

            $resumen['ajustados'] = $ajustados;

            Auditor::registrar('TOMA_INVENTARIO_CERRADA', 'tomas_inventario', $bloqueada->id, [
                'alcance' => $bloqueada->alcance,
                'productos' => $resumen['total'],
                'contados' => $resumen['contados'],
                'ajustados' => $ajustados,
                'faltante' => $resumen['faltante'],
                'sobrante' => $resumen['sobrante'],
            ], $usuario->id);

            return $resumen;
        }, 3);
    }

    /** Se abandona sin tocar el stock: los conteos quedan guardados como constancia. */
    public static function cancelar(TomaInventario $toma, Usuario $usuario): void
    {
        DB::transaction(function () use ($toma, $usuario) {
            $bloqueada = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            self::exigirAbierta($bloqueada, 'cancelarla');

            $bloqueada->update([
                'estado' => 'CANCELADA',
                'usuario_cierre_id' => $usuario->id,
                'fecha_cierre' => now(),
            ]);

            Auditor::registrar('TOMA_INVENTARIO_CANCELADA', 'tomas_inventario', $bloqueada->id, [
                'alcance' => $bloqueada->alcance,
                'contados' => self::resumen($bloqueada)['contados'],
            ], $usuario->id);
        });
    }

    /**
     * Cuántos productos hay, cuántos se contaron, y lo que falta y sobra al costo.
     *
     * @return array{total: int, contados: int, con_diferencia: int, faltante: float, sobrante: float}
     */
    public static function resumen(TomaInventario $toma): array
    {
        $fila = DB::table('toma_inventario_detalle')
            ->where('toma_id', $toma->id)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(contado IS NOT NULL), 0) AS contados')
            ->selectRaw('COALESCE(SUM(diferencia <> 0), 0) AS con_diferencia')
            ->selectRaw('COALESCE(SUM(CASE WHEN diferencia < 0 THEN ROUND(-diferencia * costo_unitario, 2) ELSE 0 END), 0) AS faltante')
            ->selectRaw('COALESCE(SUM(CASE WHEN diferencia > 0 THEN ROUND(diferencia * costo_unitario, 2) ELSE 0 END), 0) AS sobrante')
            ->first();

        return [
            'total' => (int) $fila->total,
            'contados' => (int) $fila->contados,
            'con_diferencia' => (int) $fila->con_diferencia,
            'faltante' => round((float) $fila->faltante, 2),
            'sobrante' => round((float) $fila->sobrante, 2),
        ];
    }

    private static function exigirAbierta(TomaInventario $toma, string $para): void
    {
        if ($toma->estado === 'CERRADA') {
            throw new RuntimeException("La toma #{$toma->id} ya está cerrada: no se puede {$para}.");
        }

        if ($toma->estado === 'CANCELADA') {
            throw new RuntimeException("La toma #{$toma->id} fue cancelada: no se puede {$para}.");
        }
    }
}
