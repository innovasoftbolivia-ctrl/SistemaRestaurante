<?php

namespace App\Support;

use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Lo que hay que comprar, para la campana de la cabecera: las bebidas en su
 * stock mínimo o por debajo, y las que quedaron en negativo (se vendieron sin
 * registrar la compra). La campana está en todas las pantallas: avisa antes
 * de que el cliente pida algo que ya no queda.
 *
 * Solo para quien puede hacer algo con el aviso: comprar o ajustar.
 */
class AlertasStock
{
    /** Cuántos se listan en el desplegable; el resto, en el inventario. */
    public const MOSTRAR = 8;

    public static function puedeVer(?Usuario $usuario): bool
    {
        return $usuario?->tienePermiso('inventario.gestionar') ?? false;
    }

    /**
     * Cuántos hay, cuántos ya no tienen (0 o negativo) y los primeros: los
     * agotados antes, después los más lejos de su mínimo.
     *
     * @return array{total: int, agotados: int, productos: Collection<int, Producto>}|null
     */
    public static function para(?Usuario $usuario): ?array
    {
        if (! self::puedeVer($usuario)) {
            return null;
        }

        $alertas = Producto::conStock()->activos()->whereColumn('stock_actual', '<=', 'stock_minimo');

        $total = (clone $alertas)->count();

        return [
            'total' => $total,
            'agotados' => (clone $alertas)->where('stock_actual', '<=', 0)->count(),
            'productos' => $total === 0 ? collect() : $alertas
                ->with('categoria:id,nombre')
                ->orderByRaw('stock_actual <= 0 DESC')
                ->orderByRaw('stock_minimo - stock_actual DESC')
                ->orderBy('nombre')
                ->limit(self::MOSTRAR)
                ->get(),
        ];
    }
}
