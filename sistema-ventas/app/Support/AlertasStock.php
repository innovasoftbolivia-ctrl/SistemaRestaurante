<?php

namespace App\Support;

use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los productos que hay que reponer, para la campana de la cabecera.
 *
 * Hasta ahora solo salían en el panel de inicio, y ahí no los ve nadie que
 * esté trabajando en otra pantalla. La campana está en todas y dice cuántos
 * hay antes de que el cliente pregunte por algo que ya no queda.
 *
 * Misma regla que {@see Producto::alertasDeStock()} —stock en su mínimo o por
 * debajo—, así la campana, el panel y el reporte dan siempre el mismo número.
 */
class AlertasStock
{
    /** Cuántos se listan en el desplegable; el resto, en el inventario. */
    public const MOSTRAR = 8;

    /** A quien puede hacer algo con la alerta: reponer, comprar o decidir. */
    public static function puedeVer(?Usuario $usuario): bool
    {
        return $usuario !== null && collect(['inventario.ingresar', 'inventario.ajustar', 'productos.gestionar', 'reportes.ver'])
            ->contains(fn (string $permiso) => $usuario->tienePermiso($permiso));
    }

    /**
     * Cuántos hay, cuántos ya se agotaron y los primeros de la lista: los
     * agotados antes, y después los que más lejos están de su mínimo.
     *
     * @return array{total: int, agotados: int, productos: Collection<int, object>}|null
     */
    public static function para(?Usuario $usuario): ?array
    {
        if (! self::puedeVer($usuario)) {
            return null;
        }

        $cuentas = DB::query()
            ->fromSub(Producto::alertasDeStock(), 'a')
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(a.stock_actual <= 0), 0) AS agotados')
            ->first();

        $total = (int) $cuentas->total;

        return [
            'total' => $total,
            'agotados' => (int) $cuentas->agotados,
            'productos' => $total === 0 ? collect() : Producto::alertasDeStock()
                ->orderByRaw('p.stock_actual <= 0 DESC')
                ->orderByDesc('faltante')
                ->orderBy('p.nombre')
                ->limit(self::MOSTRAR)
                ->get(),
        ];
    }
}
