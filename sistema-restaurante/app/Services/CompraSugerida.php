<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Proveedor;
use App\Support\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El pedido al proveedor que el sistema arma solo: lo que está en su stock
 * mínimo o por debajo, en cajas cerradas, separado por proveedor.
 *
 * Cuánto pedir: lo que falta para llegar al mínimo MÁS lo que se vende en una
 * semana (el promedio de las últimas dos). Así el pedido no deja el producto
 * justo en el mínimo para volver a pedir mañana. Lo que no se vendió en ese
 * tiempo se repone hasta el doble del mínimo.
 *
 * A quién: al último proveedor que lo trajo. Lo que nunca se compró va aparte,
 * para elegirle proveedor al armar la compra. El costo es el de la última
 * compra: es una estimación, la factura manda.
 *
 * Solo sugiere: nada se registra hasta que alguien revisa la compra y la
 * guarda.
 */
class CompraSugerida
{
    /** Días de ventas que se promedian. */
    public const DIAS_HISTORIA = 14;

    /** Días de venta que el pedido cubre por encima del mínimo. */
    public const DIAS_COBERTURA = 7;

    /**
     * Las líneas sugeridas, agrupadas por proveedor (la clave `0` es «sin
     * proveedor anterior»).
     *
     * @return Collection<int, array{proveedor: ?Proveedor, lineas: Collection<int, array<string, mixed>>, total: float}>
     */
    public static function porProveedor(): Collection
    {
        $productos = Producto::conStock()->activos()
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->orderBy('nombre')
            ->get();

        if ($productos->isEmpty()) {
            return collect();
        }

        $ids = $productos->pluck('id');
        $vendidas = self::vendidas($ids);
        $porReponer = self::porReponer($ids);
        $ultimoProveedor = self::ultimoProveedor($ids);
        $proveedores = Proveedor::whereIn('id', $ultimoProveedor->filter()->unique())->get()->keyBy('id');

        return $productos
            ->map(function (Producto $p) use ($vendidas, $porReponer, $ultimoProveedor, $proveedores) {
                $linea = self::linea($p, (float) ($vendidas[$p->id] ?? 0), (float) ($porReponer[$p->id] ?? 0));
                $proveedorId = (int) ($ultimoProveedor[$p->id] ?? 0);
                // Un proveedor desactivado no recibe compras: se elige otro.
                $linea['proveedor_id'] = ($proveedores[$proveedorId] ?? null)?->activo ? $proveedorId : 0;

                return $linea;
            })
            ->filter(fn (array $l) => $l['cantidad'] > 0)
            ->groupBy('proveedor_id')
            ->map(fn (Collection $lineas, $id) => [
                'proveedor' => $proveedores[$id] ?? null,
                'lineas' => $lineas->values(),
                'total' => round($lineas->sum('importe'), 2),
            ])
            // Los que tienen proveedor primero; «sin proveedor» al final.
            ->sortBy(fn ($g, $id) => $id === 0 ? 'zzz' : $g['proveedor']->razon_social)
            ->values();
    }

    /**
     * Cuánto pedir de un producto: hasta el mínimo más una semana de venta,
     * redondeado para arriba a cajas cerradas.
     *
     * @return array<string, mixed>
     */
    public static function linea(Producto $p, float $vendidasEnHistoria, float $porReponer = 0): array
    {
        $stock = (float) $p->stock_actual;
        $minimo = (float) $p->stock_minimo;
        $porSemana = $vendidasEnHistoria / self::DIAS_HISTORIA * self::DIAS_COBERTURA;

        $objetivo = $porSemana > 0 ? $minimo + $porSemana : max($minimo * 2, 1);
        // Lo que el proveedor todavía debe (una devolución PENDIENTE) va a
        // llegar: pedirlo de nuevo dejaba el doble cuando llegara.
        $falta = max(0, $objetivo - $stock - $porReponer);

        $contenido = (float) $p->contenido_empaque;
        $conEmpaque = $contenido > 1 && $p->nombre_empaque;
        $empaques = $conEmpaque ? (int) ceil($falta / $contenido - 1e-9) : 0;
        $cantidad = $conEmpaque ? $empaques * $contenido : ceil($falta - 1e-9);
        $costo = $p->costo !== null ? (float) $p->costo : null;

        return [
            'producto' => $p,
            'stock' => $stock,
            'minimo' => $minimo,
            'por_reponer' => $porReponer,
            'por_semana' => round($porSemana, 1),
            'empaques' => $empaques,
            'cantidad' => $cantidad,
            'costo' => $costo,
            'importe' => $costo !== null ? round($cantidad * $costo, 2) : 0.0,
        ];
    }

    /**
     * Las líneas de un proveedor, listas para el formulario de la compra
     * (el mismo formato que devuelve una validación que rebota).
     *
     * @return array<int, array<string, string>>
     */
    public static function paraElFormulario(int $proveedorId): array
    {
        $grupo = self::porProveedor()->first(fn ($g) => ($g['proveedor']?->id ?? 0) === $proveedorId);

        return collect($grupo['lineas'] ?? [])
            ->map(fn (array $l) => [
                'producto_id' => (string) $l['producto']->id,
                // En cajas cerradas si viene en caja; si no, en unidades.
                'empaques' => $l['empaques'] > 0 ? (string) $l['empaques'] : '',
                'sueltas' => $l['empaques'] > 0 ? '' : (string) (int) $l['cantidad'],
                // Hasta cuatro decimales (el costo por unidad de una caja), sin
                // ceros de más.
                'costo' => $l['costo'] !== null ? preg_replace('/(\.\d\d)0+$/', '$1', number_format($l['costo'], 4, '.', '')) : '',
                'costo_por' => 'unidad',
            ])
            ->values()
            ->all();
    }

    /** Unidades vendidas por producto en las últimas jornadas, sin las anuladas. */
    private static function vendidas(Collection $ids): Collection
    {
        $hasta = Carbon::parse(Config::jornadaActual());
        $desde = $hasta->copy()->subDays(self::DIAS_HISTORIA - 1);

        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->whereIn('d.producto_id', $ids)
            ->whereBetween('v.fecha', Config::momentosDeJornadas($desde, $hasta))
            ->groupBy('d.producto_id')
            ->selectRaw('d.producto_id, SUM(d.cantidad) AS unidades')
            ->pluck('unidades', 'producto_id');
    }

    /** Lo que los proveedores deben reponer de cada producto (devoluciones PENDIENTE). */
    private static function porReponer(Collection $ids): Collection
    {
        return DB::table('devolucion_compra_detalle as d')
            ->join('devoluciones_compra as dc', 'dc.id', '=', 'd.devolucion_compra_id')
            ->where('dc.espera', 'PENDIENTE')
            ->whereIn('d.producto_id', $ids)
            ->groupBy('d.producto_id')
            ->selectRaw('d.producto_id, SUM(d.cantidad - d.cantidad_repuesta) AS debe')
            ->pluck('debe', 'producto_id');
    }

    /** El proveedor de la última compra de cada producto. */
    private static function ultimoProveedor(Collection $ids): Collection
    {
        return DB::table('compra_detalle as cd')
            ->join('compras as c', 'c.id', '=', 'cd.compra_id')
            ->whereIn('cd.producto_id', $ids)
            ->orderBy('c.fecha')
            ->orderBy('c.id')
            ->get(['cd.producto_id', 'c.proveedor_id'])
            // El último gana: están ordenadas de la más vieja a la más nueva.
            ->pluck('proveedor_id', 'producto_id');
    }
}
