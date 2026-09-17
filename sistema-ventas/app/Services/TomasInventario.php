<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
     *
     * `$contadoEn` es para el conteo en papel que se carga después: la foto
     * del sistema se toma a la hora en que se contó el estante, reconstruida
     * con el kardex, y no a la hora en que se teclea. Sin eso, lo vendido
     * entre contar y cargar se sumaba al stock al cerrar.
     */
    public static function contar(TomaInventarioDetalle $linea, Usuario $usuario, ?float $contado, ?Carbon $contadoEn = null): TomaInventarioDetalle
    {
        if ($contado !== null && $contado < 0) {
            throw new RuntimeException('El conteo no puede ser negativo.');
        }

        if ($contadoEn !== null && $contadoEn->isFuture()) {
            throw new RuntimeException('La hora del conteo no puede ser posterior a ahora.');
        }

        return DB::transaction(function () use ($linea, $usuario, $contado, $contadoEn) {
            // Bloqueo compartido: varios pueden contar a la vez, pero nadie
            // mientras se cierra (el cierre la bloquea en exclusiva).
            $toma = TomaInventario::whereKey($linea->toma_id)->sharedLock()->firstOrFail();
            self::exigirAbierta($toma, 'cambiar un conteo');

            if (TomaInventarioDetalle::whereKey($linea->id)->lockForUpdate()->value('movimiento_id') !== null) {
                throw new RuntimeException('Ese conteo ya se aplicó al stock al cerrar la toma: no se puede cambiar.');
            }

            if ($contado === null) {
                $linea->update([
                    'contado' => null, 'stock_sistema' => null, 'costo_unitario' => null,
                    'usuario_id' => null, 'fecha_conteo' => null,
                ]);
            } else {
                $producto = Producto::whereKey($linea->producto_id)->firstOrFail(['id', 'stock_actual', 'precio_compra']);

                if ($contadoEn !== null && $contadoEn->lt($toma->fecha_apertura)) {
                    throw new RuntimeException('La hora del conteo es anterior a la apertura de la toma.');
                }

                // Un ajuste hecho DESPUÉS de contar en papel ya corrigió el
                // stock con otro conteo: aplicar además la diferencia del papel
                // restaría dos veces lo mismo (contado 10 contra 12 a las 10:00,
                // ajustado a 10 a las 11:00, el cierre dejaba 8).
                if ($contadoEn !== null && DB::table('movimientos_inventario')
                    ->where('producto_id', $producto->id)
                    ->where('origen', 'AJUSTE')
                    ->where('fecha', '>', $contadoEn)
                    ->exists()) {
                    throw new RuntimeException('Después de esa hora se ajustó el stock de este producto: el conteo en papel ya no vale. Vuelve a contarlo en el estante.');
                }

                $linea->update([
                    'contado' => round($contado, 3),
                    'stock_sistema' => $contadoEn ? self::stockA($producto, $contadoEn) : $producto->stock_actual,
                    'costo_unitario' => $producto->precio_compra,
                    'usuario_id' => $usuario->id,
                    'fecha_conteo' => $contadoEn ?? now(),
                ]);
            }

            return $linea->refresh();
        });
    }

    /**
     * Aplica las diferencias y cierra la toma.
     *
     * Cada producto se ajusta en su propia transacción corta. Antes era una
     * sola para toda la tienda, y cada producto quedaba bloqueado desde que se
     * ajustaba hasta el último: con cientos de productos, una venta de
     * cualquiera de ellos esperaba —o se caía por tiempo— hasta que terminara
     * el cierre entero.
     *
     * Lo que sostiene esto es que cada línea recuerda su ajuste
     * (`movimiento_id`): si el cierre se corta a la mitad, la toma sigue
     * ABIERTA, volver a cerrarla continúa donde quedó y nada se aplica dos
     * veces. Mientras tanto, lo ya aplicado no se puede recontar ni cancelar.
     *
     * @return array{total: int, contados: int, con_diferencia: int, faltante: float, sobrante: float, ajustados: int}
     */
    public static function cerrar(TomaInventario $toma, Usuario $usuario): array
    {
        $vigente = TomaInventario::whereKey($toma->id)->firstOrFail();
        self::exigirAbierta($vigente, 'cerrarla');

        if (self::resumen($vigente)['contados'] === 0) {
            throw new RuntimeException('No se contó ningún producto. Si no se va a contar, cancela la toma.');
        }

        // Un cierre a la vez: dos personas apretando «Cerrar» no recorren las
        // líneas en paralelo. El candado es de la conexión, no de una
        // transacción, así que no retiene ninguna fila.
        $candado = "toma_inventario_cierre_{$vigente->id}";

        if (! (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS ok', [$candado])->ok) {
            throw new RuntimeException('Alguien ya está cerrando esta toma. Espera un momento y recarga la página.');
        }

        try {
            $motivo = "Toma de inventario #{$vigente->id}";
            // Los ajustes quedan a nombre de quien cierra, también fuera de
            // una petición web (consola, cola), donde no hay sesión.
            $usuarioId = $usuario->id;

            $pendientes = TomaInventarioDetalle::where('toma_id', $vigente->id)
                ->whereNotNull('contado')
                ->where('diferencia', '<>', 0)
                ->whereNull('movimiento_id')
                ->orderBy('id')
                ->pluck('id');

            foreach ($pendientes as $id) {
                DB::transaction(function () use ($id, $motivo, $usuarioId) {
                    $linea = TomaInventarioDetalle::whereKey($id)->lockForUpdate()->with('producto')->first();

                    // Otro ajuste la dejó sin contar, o ya se aplicó.
                    if (! $linea || $linea->contado === null || $linea->movimiento_id !== null || (float) $linea->diferencia === 0.0) {
                        return;
                    }

                    $movimiento = Inventario::corregir($linea->producto, (float) $linea->diferencia, $motivo, $usuarioId);

                    if ($movimiento) {
                        $linea->update(['movimiento_id' => $movimiento->id]);
                    }
                }, 3);
            }

            return DB::transaction(function () use ($vigente, $usuario) {
                $bloqueada = TomaInventario::whereKey($vigente->id)->lockForUpdate()->firstOrFail();
                self::exigirAbierta($bloqueada, 'cerrarla');

                $bloqueada->update([
                    'estado' => 'CERRADA',
                    'usuario_cierre_id' => $usuario->id,
                    'fecha_cierre' => now(),
                ]);

                $resumen = self::resumen($bloqueada);
                $resumen['ajustados'] = TomaInventarioDetalle::where('toma_id', $bloqueada->id)->whereNotNull('movimiento_id')->count();
                $ajustados = $resumen['ajustados'];

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
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS ok', [$candado]);
        }
    }

    /** Se abandona sin tocar el stock: los conteos quedan guardados como constancia. */
    public static function cancelar(TomaInventario $toma, Usuario $usuario): void
    {
        DB::transaction(function () use ($toma, $usuario) {
            $bloqueada = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            self::exigirAbierta($bloqueada, 'cancelarla');

            if (TomaInventarioDetalle::where('toma_id', $bloqueada->id)->whereNotNull('movimiento_id')->exists()) {
                throw new RuntimeException('Un cierre ya empezó a ajustar el stock de esta toma: termina de cerrarla en vez de cancelarla.');
            }

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

    /**
     * El stock que tenía un producto a una hora dada, según el kardex: el
     * saldo del último movimiento hasta esa hora, o el saldo previo del primero
     * que vino después. Sin movimientos, el stock de ahora.
     */
    public static function stockA(Producto $producto, Carbon $momento): float
    {
        $antes = DB::table('movimientos_inventario')
            ->where('producto_id', $producto->id)
            ->where('fecha', '<=', $momento)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->value('stock_resultante');

        if ($antes !== null) {
            return (float) $antes;
        }

        $despues = DB::table('movimientos_inventario')
            ->where('producto_id', $producto->id)
            ->where('fecha', '>', $momento)
            ->orderBy('fecha')->orderBy('id')
            ->value('stock_anterior');

        return $despues !== null ? (float) $despues : (float) $producto->stock_actual;
    }

    /**
     * Un ajuste o una baja fuera de la toma, sobre un producto que ya se contó
     * en una toma abierta, deja ese conteo sin valor: el cierre aplicaría la
     * misma diferencia otra vez. Se borra el conteo para que se vuelva a
     * contar. Devuelve el número de la toma afectada, si la hubo.
     *
     * No lo llama el cierre de la propia toma, que corrige por
     * {@see Inventario::corregir()}.
     */
    public static function olvidarConteoDe(Producto $producto): ?int
    {
        $linea = TomaInventarioDetalle::query()
            ->where('producto_id', $producto->id)
            ->whereNotNull('contado')
            ->whereNull('movimiento_id')
            ->whereHas('toma', fn ($q) => $q->where('estado', 'ABIERTA'))
            ->first();

        if (! $linea) {
            return null;
        }

        $linea->update([
            'contado' => null, 'stock_sistema' => null, 'costo_unitario' => null,
            'usuario_id' => null, 'fecha_conteo' => null,
        ]);

        return (int) $linea->toma_id;
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
