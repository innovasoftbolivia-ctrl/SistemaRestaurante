<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Único punto por el que cambian los lotes de un producto.
 *
 * La regla que ordena todo esto: `productos.stock_actual` sigue mandando. Es el
 * saldo que mira el mostrador, el que valida la venta y el que sale en los
 * reportes. Los lotes NO son una segunda contabilidad — son ese mismo saldo
 * partido por fecha de vencimiento, y la suma de sus `cantidad_actual` tiene
 * que dar exactamente `stock_actual`.
 *
 * Por eso este servicio no toca el stock nunca: lo hace {@see Inventario}, y
 * aquí solo se reparte. Si algún día las dos cifras se separaran, la de
 * `productos` sería la buena y la de lotes la sospechosa.
 *
 * Dónde se engancha: en los cuatro sitios de PHP por los que se mueve stock —
 * la venta, la devolución, la anulación y el inventario—. Se eligieron esos y
 * no los triggers de MySQL a propósito: el sistema tiene dos vías para
 * descontar stock (el trigger en un servidor propio, `ReglasEnPhp` en un
 * hosting sin triggers) y las dos pasan por ese mismo código PHP. Escribirlo
 * ahí significa una sola implementación en vez de dos que hay que mantener
 * iguales a mano.
 */
class Lotes
{
    /** Cuántos días antes se considera que un producto «está por vencer». */
    public const DIAS_DE_AVISO = 30;

    /**
     * Mercadería que entra con su fecha.
     *
     * Cada entrada abre su propio lote aunque coincida la fecha con otro: dos
     * cajas del mismo vencimiento compradas en semanas distintas son dos
     * tandas, y juntarlas perdería de cuál factura vino cada una.
     */
    public static function ingresar(
        Producto $producto,
        float $cantidad,
        ?string $fechaVencimiento = null,
        ?string $codigo = null,
        ?int $compraDetalleId = null,
    ): ?Lote {
        if (! $producto->controla_vencimiento || $cantidad <= 0) {
            return null;
        }

        return Lote::create([
            'producto_id' => $producto->id,
            'codigo' => $codigo,
            'fecha_vencimiento' => $fechaVencimiento ?: null,
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'compra_detalle_id' => $compraDetalleId,
        ]);
    }

    /**
     * Mercadería que sale: se descuenta del lote que vence antes (FEFO).
     *
     * Una venta puede barrer varios lotes —quedan 5 del que vence el martes y
     * se llevan 12—, así que se va restando en orden hasta cubrir la cantidad.
     *
     * Si los lotes no alcanzan, la diferencia se descuenta igual del último
     * abierto y no se lanza ningún error. Parece raro pero es deliberado: el
     * stock ya lo validó {@see Inventario} o el trigger de la venta, y hacer
     * fallar aquí una venta legítima porque el reparto por lotes quedó
     * incompleto —al encender el control con stock ya existente, por ejemplo—
     * sería castigar al cajero por un descuadre que no es suyo. La cifra que
     * manda es `stock_actual`, y los lotes se acomodan a ella.
     */
    public static function consumir(
        Producto $producto,
        float $cantidad,
        ?int $ventaDetalleId = null,
        bool $vencidoPrimero = false,
    ): void {
        if (! $producto->controla_vencimiento || $cantidad <= 0) {
            return;
        }

        $porRepartir = round($cantidad, 3);

        // `lockForUpdate`: dos cajeros vendiendo el mismo producto a la vez se
        // repartirían el mismo lote dos veces sin este bloqueo.
        $lotes = Lote::where('producto_id', $producto->id)
            ->abiertos()
            ->when($vencidoPrimero, fn ($q) => $q->vencidoPrimero(), fn ($q) => $q->enOrdenDeSalida())
            ->lockForUpdate()
            ->get();

        foreach ($lotes as $lote) {
            if ($porRepartir <= 0) {
                break;
            }

            $sale = min((float) $lote->cantidad_actual, $porRepartir);

            $lote->forceFill(['cantidad_actual' => round((float) $lote->cantidad_actual - $sale, 3)])->save();

            // Si es una venta, se anota de qué lote salió: la anulación y la
            // devolución reponen ahí mismo.
            if ($ventaDetalleId !== null && $sale > 0) {
                DB::table('lote_salidas')->insert([
                    'lote_id' => $lote->id,
                    'venta_detalle_id' => $ventaDetalleId,
                    'cantidad' => $sale,
                ]);
            }

            $porRepartir = round($porRepartir - $sale, 3);
        }
    }

    /**
     * Lo que vuelve de una línea de venta, a los lotes de los que salió.
     *
     * Primero al que salió último (el que vence más tarde): de lo que se llevó
     * el cliente, eso es lo más reciente. Lo que no tenga salida registrada
     * —ventas anteriores a este registro— se repone como siempre.
     */
    public static function reponerDeVenta(Producto $producto, int $ventaDetalleId, float $cantidad): void
    {
        if (! $producto->controla_vencimiento || $cantidad <= 0) {
            return;
        }

        $porReponer = round($cantidad, 3);

        $salidas = DB::table('lote_salidas as s')
            ->join('lotes as l', 'l.id', '=', 's.lote_id')
            ->where('s.venta_detalle_id', $ventaDetalleId)
            ->whereColumn('s.repuesta', '<', 's.cantidad')
            ->orderByRaw('l.fecha_vencimiento IS NULL')
            ->orderByDesc('l.fecha_vencimiento')
            ->orderByDesc('s.id')
            ->lockForUpdate()
            ->get(['s.id', 's.lote_id', 's.cantidad', 's.repuesta']);

        foreach ($salidas as $salida) {
            if ($porReponer <= 0) {
                break;
            }

            $vuelve = min(round((float) $salida->cantidad - (float) $salida->repuesta, 3), $porReponer);

            DB::table('lotes')->where('id', $salida->lote_id)
                ->update(['cantidad_actual' => DB::raw('ROUND(cantidad_actual + '.number_format($vuelve, 3, '.', '').', 3)')]);
            DB::table('lote_salidas')->where('id', $salida->id)
                ->update(['repuesta' => round((float) $salida->repuesta + $vuelve, 3)]);

            $porReponer = round($porReponer - $vuelve, 3);
        }

        if ($porReponer > 0) {
            self::reponer($producto, $porReponer);
        }
    }

    /**
     * Descuenta de UNA tanda concreta, no de la que toque por orden.
     *
     * Es lo que pide la devolución al proveedor: cuando se devuelve algo
     * vencido se devuelve ESE lote, y no el que saldría primero. Si no se
     * eligió tanda, se cae al reparto normal (FEFO), que es lo correcto para
     * un producto que no lleva fechas.
     *
     * Si el lote elegido no alcanza, la diferencia se toma del resto por orden
     * de salida: el stock ya lo validó quien llamó, y la cifra que manda es
     * `stock_actual`.
     */
    public static function consumirDe(Producto $producto, float $cantidad, ?Lote $lote): void
    {
        if (! $producto->controla_vencimiento || $cantidad <= 0) {
            return;
        }

        if (! $lote) {
            self::consumir($producto, $cantidad);

            return;
        }

        $lote->refresh();
        $sale = min((float) $lote->cantidad_actual, round($cantidad, 3));

        if ($sale > 0) {
            $lote->forceFill(['cantidad_actual' => round((float) $lote->cantidad_actual - $sale, 3)])->save();
        }

        $resto = round($cantidad - $sale, 3);

        if ($resto > 0) {
            self::consumir($producto, $resto);
        }
    }

    /**
     * Mercadería que vuelve sin saberse de qué lote salió.
     *
     * Es el caso de la devolución de un cliente y el de la anulación de una
     * venta: las unidades vuelven al estante, pero salieron hace días y nadie
     * anotó de qué tanda eran. Se reponen en el lote abierto que vence antes
     * —el mismo por el que habrían salido— y, si no queda ninguno abierto, se
     * abre uno sin fecha. Inventar una fecha sería peor que admitir que no se
     * sabe.
     */
    public static function reponer(Producto $producto, float $cantidad): void
    {
        if (! $producto->controla_vencimiento || $cantidad <= 0) {
            return;
        }

        $lote = Lote::where('producto_id', $producto->id)
            ->abiertos()
            ->enOrdenDeSalida()
            ->lockForUpdate()
            ->first();

        if (! $lote) {
            self::ingresar($producto, $cantidad);

            return;
        }

        // El techo del CHECK es `cantidad_inicial`: lo devuelto sube las dos,
        // porque de este lote acabó saliendo menos de lo que se creía.
        $lote->forceFill([
            'cantidad_actual' => round((float) $lote->cantidad_actual + $cantidad, 3),
            'cantidad_inicial' => round((float) $lote->cantidad_inicial + $cantidad, 3),
        ])->save();
    }

    /**
     * Pone los lotes a cuadrar con el stock que ya tenía el producto.
     *
     * Se llama al encender el control en un producto que lleva meses en el
     * catálogo: esas unidades existen, están en el estante y hay que contarlas,
     * pero su fecha no la sabe nadie. Se abre un lote sin fecha por la
     * diferencia, que la pantalla muestra aparte como «sin fecha registrada»
     * para que se vea que el control todavía no está completo.
     */
    public static function cuadrarConElStock(Producto $producto): void
    {
        if (! $producto->controla_vencimiento) {
            return;
        }

        $enLotes = (float) Lote::where('producto_id', $producto->id)->sum('cantidad_actual');
        $falta = round((float) $producto->stock_actual - $enLotes, 3);

        if ($falta > 0) {
            self::ingresar($producto, $falta);
        }

        // Y al revés: si mientras el control estuvo apagado se vendió sin
        // descontar lotes, quedaron unidades que ya no existen. Se recortan
        // por el mismo orden por el que habrían salido.
        if ($falta < 0) {
            self::consumir($producto, abs($falta));
        }
    }

    /**
     * Cuántas unidades de este producto no tienen fecha conocida.
     *
     * Se muestra para que nadie confunda «no tengo nada por vencer» con «no
     * tengo nada fechado».
     */
    public static function sinFecha(Producto $producto): float
    {
        return round((float) Lote::where('producto_id', $producto->id)
            ->abiertos()
            ->whereNull('fecha_vencimiento')
            ->sum('cantidad_actual'), 3);
    }

    /**
     * Las filas de la alerta: qué está vencido y qué está por vencer.
     *
     * Va por el query builder y no por Eloquent por el mismo motivo que
     * `Producto::alertasDeStock()`: es una consulta de reporte y no necesita
     * hidratar modelos.
     */
    public static function alertas(int $dias = self::DIAS_DE_AVISO): Builder
    {
        return DB::table('lotes as l')
            ->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->leftJoin('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            // De qué factura vino esta tanda, si vino de alguna. Es lo que
            // permite ofrecer «devolver al proveedor» desde aquí en vez de
            // obligar a recordar con qué papel entró: el dato ya estaba
            // guardado y no servía para nada.
            ->leftJoin('compra_detalle as cd', 'cd.id', '=', 'l.compra_detalle_id')
            ->leftJoin('compras as c', 'c.id', '=', 'cd.compra_id')
            ->where('p.activo', 1)
            // Un producto al que se le apagó el control no avisa: sus lotes ya
            // no siguen al stock.
            ->where('p.controla_vencimiento', 1)
            ->where('l.cantidad_actual', '>', 0)
            ->whereNotNull('l.fecha_vencimiento')
            ->whereDate('l.fecha_vencimiento', '<=', now()->addDays($dias))
            ->selectRaw('l.id, l.fecha_vencimiento, l.cantidad_actual, l.codigo')
            ->selectRaw('p.id AS producto_id, p.codigo AS producto_codigo, p.nombre AS producto')
            ->selectRaw('u.codigo AS unidad')
            ->selectRaw('DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias')
            ->selectRaw('ROUND(l.cantidad_actual * p.precio_compra, 2) AS valor')
            ->selectRaw('c.id AS compra_id, c.documento_externo AS compra_documento')
            // Solo se ofrece devolver si a esa línea le queda algo sin
            // devolver: si ya se devolvió entera, el formulario saldría topado
            // en cero.
            ->selectRaw('COALESCE(cd.cantidad - cd.cantidad_devuelta, 0) AS pendiente_devolucion')
            ->orderBy('l.fecha_vencimiento')
            ->orderBy('p.nombre');
    }
}
