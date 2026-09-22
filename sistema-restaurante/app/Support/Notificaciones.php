<?php

namespace App\Support;

use App\Http\Controllers\CocinaController;
use App\Models\DevolucionCompra;
use App\Models\Pedido;
use App\Models\SesionCaja;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La campana de la cabecera: lo que pide que alguien haga algo AHORA y no se
 * ve en otro lado. Pocas cosas a propósito: una campana que avisa de todo deja
 * de mirarse.
 *
 *   Cocina      un pedido que espera hace demasiado; uno listo que nadie
 *               lleva; uno con el cobro anulado que no se volvió a cobrar.
 *   Caja        un turno que cerró con faltante o sobrante; las ventas
 *               anuladas de la jornada (el control más simple contra robos).
 *   Inventario  lo que hay que comprar; el proveedor que debe una reposición.
 *
 * No hay «marcar como leída»: cada aviso sale mientras el problema exista y se
 * va solo cuando se resuelve (el pedido sale, se cobra, el proveedor repone).
 * Los de la jornada —el descuadre, las anulaciones— se van con ella.
 *
 * Cada rol ve lo suyo: el cajero, lo de los pedidos; el administrador, todo.
 * Cada aviso lleva a la pantalla donde se resuelve.
 */
class Notificaciones
{
    /** Minutos desde los que un pedido que no sale de la cocina es un aviso. */
    public const MINUTOS_DEMORA = CocinaController::MINUTOS_TARDE;

    /** Minutos que un pedido listo puede esperar a que alguien lo lleve. */
    public const MINUTOS_LISTO = 5;

    /** Días que se espera la reposición del proveedor antes de avisar. */
    public const DIAS_PROVEEDOR = 7;

    /** Avisos que se listan por grupo; el resto, en su pantalla. */
    public const MOSTRAR = 5;

    /**
     * Los avisos de quien mira, por grupo, más el total y si alguno es grave.
     * `null` si su rol no tiene nada que vigilar (la cocina ya mira su pantalla).
     *
     * @return array{total: int, grave: bool, grupos: array<string, array{titulo: string, avisos: array<int, array{nivel: string, titulo: string, detalle: ?string, url: ?string}>, stock?: int}>}|null
     */
    public static function para(?Usuario $usuario): ?array
    {
        if ($usuario === null) {
            return null;
        }

        $gestion = $usuario->tienePermiso('reportes.ver');
        $vende = $usuario->tienePermiso('ventas.registrar');
        $entrega = $usuario->tienePermiso('cocina.entregar') || $usuario->tienePermiso('cocina.ver');
        $inventario = $usuario->tienePermiso('inventario.gestionar');

        if (! $gestion && ! $vende && ! $inventario) {
            return null;
        }

        $grupos = [];

        // ---- cocina
        $cocina = [];
        if ($gestion || $vende) {
            $cocina = self::pedidosDeLaCocina($gestion || $entrega, $entrega);
            array_push($cocina, ...self::porCobrar($vende));
        }
        if ($cocina) {
            $grupos['cocina'] = ['titulo' => 'Pedidos', 'avisos' => $cocina];
        }

        // ---- caja
        if ($gestion) {
            $caja = [...self::descuadres(), ...self::anuladas()];
            if ($caja) {
                $grupos['caja'] = ['titulo' => 'Caja', 'avisos' => $caja];
            }
        }

        // ---- inventario
        if ($inventario) {
            $stock = AlertasStock::para($usuario);
            $avisos = $stock['productos']->map(fn ($p) => [
                'nivel' => (float) $p->stock_actual <= 0 ? 'peligro' : 'aviso',
                'titulo' => $p->nombre,
                'detalle' => ((float) $p->stock_actual < 0
                    ? 'Negativo ('.Config::cantidad($p->stock_actual).'): se vendió sin stock'
                    : ((float) $p->stock_actual <= 0 ? 'Sin stock' : 'Quedan '.Config::cantidad($p->stock_actual)))
                    .' · mín. '.Config::cantidad($p->stock_minimo),
                'url' => route('inventario.kardex', $p),
                'stock' => true,
            ])->all();
            $proveedor = self::proveedoresQueDeben();

            if ($avisos || $proveedor) {
                $grupos['inventario'] = [
                    'titulo' => 'Inventario',
                    'avisos' => [...$proveedor, ...$avisos],
                    // Lo que hay por comprar, aunque se listen menos.
                    'stock' => $stock['total'],
                ];
            }
        }

        $todos = collect($grupos)->flatMap(fn ($g) => $g['avisos']);

        return [
            // Lo que hay por comprar cuenta entero, aunque se listen menos.
            'total' => $todos->reject(fn ($a) => $a['stock'] ?? false)->count() + ($grupos['inventario']['stock'] ?? 0),
            'grave' => $todos->contains('nivel', 'peligro'),
            'grupos' => $grupos,
        ];
    }

    /**
     * Los pedidos del tablero de la cocina que piden a alguien: el que espera
     * hace más de {@see MINUTOS_DEMORA} minutos sin salir, y el que está listo
     * hace más de {@see MINUTOS_LISTO} sin que nadie lo lleve. «Listo desde»
     * es el último cambio de sus platos (`actualizado_en`): el último que la
     * cocina marcó listo.
     */
    private static function pedidosDeLaCocina(bool $demorados, bool $listos): array
    {
        $avisos = [];
        $url = route('cocina.index');

        foreach (CocinaController::tandas() as $tanda) {
            $pedido = $tanda['pedido'];

            if ($tanda['columna'] === 'entregar') {
                $desde = $tanda['lineas']->max('actualizado_en');
                $minutos = $desde ? (int) floor(Carbon::parse($desde)->diffInSeconds(now()) / 60) : 0;

                if ($listos && $minutos >= self::MINUTOS_LISTO) {
                    $avisos[] = [
                        'nivel' => 'aviso',
                        'titulo' => "#{$pedido->numero_dia} listo hace {$minutos} min",
                        'detalle' => 'Sin entregar · '.$pedido->destino.($pedido->quien ? ' · '.$pedido->quien : ''),
                        'url' => $url,
                    ];
                }

                continue;
            }

            $minutos = $tanda['desde'] ? (int) floor($tanda['desde']->diffInSeconds(now()) / 60) : 0;

            if ($demorados && $minutos >= self::MINUTOS_DEMORA) {
                $avisos[] = [
                    'nivel' => 'peligro',
                    'titulo' => "#{$pedido->numero_dia} espera hace {$minutos} min",
                    'detalle' => ($tanda['columna'] === 'hacer' ? 'La cocina no lo empezó' : 'En la cocina').' · '.$pedido->destino,
                    'url' => $url,
                ];
            }
        }

        return $avisos;
    }

    /**
     * El pedido de la jornada con el cobro anulado que no se volvió a cobrar:
     * se comió y no está pagado. Un pedido que nunca se cobró todavía no es
     * un aviso —se cobra al pedir, y abierto es que se está armando—; el que
     * tuvo un cobro y lo perdió, sí.
     */
    private static function porCobrar(bool $puedeCobrar): array
    {
        return Pedido::query()
            ->where('estado', Pedido::ABIERTO)
            ->where('jornada', Config::jornadaActual())
            ->whereHas('ventas', fn ($q) => $q->where('estado', 'ANULADA'))
            ->orderBy('id')
            ->get()
            ->map(fn (Pedido $p) => [
                'nivel' => 'aviso',
                'titulo' => "#{$p->numero_dia} por cobrar",
                'detalle' => 'Se anuló el cobro y no se volvió a cobrar · '.$p->destino,
                'url' => $puedeCobrar ? route('pedidos.cobrar', $p) : null,
            ])
            ->all();
    }

    /** Los turnos que cerraron en la jornada con faltante o sobrante. */
    private static function descuadres(): array
    {
        return SesionCaja::query()
            ->where('estado', 'CERRADA')
            ->whereBetween('fecha_cierre', Config::momentosDeJornadas(Config::jornadaActual(), Config::jornadaActual()))
            ->where('diferencia', '<>', 0)
            ->with(['caja:id,nombre', 'usuarioApertura:id,usuario'])
            ->orderByDesc('fecha_cierre')
            ->get()
            ->map(function (SesionCaja $s) {
                $falta = (float) $s->diferencia < 0;

                return [
                    'nivel' => $falta ? 'peligro' : 'aviso',
                    'titulo' => ($s->caja?->nombre ?? 'Caja').' cerró con '.($falta ? 'faltante' : 'sobrante')
                        .' de '.Config::importe(abs((float) $s->diferencia)),
                    'detalle' => trim(($s->usuarioApertura?->usuario ?? '').' · '.$s->fecha_cierre?->format('H:i')
                        .($s->observacion_cierre ? ' · '.$s->observacion_cierre : ''), ' ·'),
                    'url' => route('caja.show', $s),
                ];
            })
            ->all();
    }

    /**
     * Las ventas anuladas en la jornada, en un solo aviso: cuántas, cuánto y
     * de quién eran. Un cajero con muchas anulaciones se nota enseguida.
     */
    private static function anuladas(): array
    {
        $jornada = Config::jornadaActual();

        $porCajero = DB::table('ventas as v')
            ->join('usuarios as u', 'u.id', '=', 'v.usuario_id')
            ->where('v.estado', 'ANULADA')
            ->whereBetween('v.anulada_en', Config::momentosDeJornadas($jornada, $jornada))
            ->groupBy('u.usuario')
            ->selectRaw('u.usuario, COUNT(*) AS cuantas, SUM(v.total) AS monto')
            ->orderByDesc('cuantas')
            ->get();

        if ($porCajero->isEmpty()) {
            return [];
        }

        $cuantas = (int) $porCajero->sum('cuantas');

        return [[
            'nivel' => 'info',
            'titulo' => $cuantas.' '.($cuantas === 1 ? 'venta anulada' : 'ventas anuladas').' hoy · '.Config::importe($porCajero->sum('monto')),
            'detalle' => $porCajero->map(fn ($f) => $f->usuario.' ('.$f->cuantas.')')->implode(', '),
            'url' => route('ventas.index', ['estado' => 'ANULADA', 'desde' => $jornada, 'hasta' => $jornada]),
        ]];
    }

    /**
     * La devolución que quedó en que el proveedor traería el reemplazo y lleva
     * más de {@see DIAS_PROVEEDOR} días sin llegar.
     */
    private static function proveedoresQueDeben(): array
    {
        return DevolucionCompra::query()
            ->where('espera', 'PENDIENTE')
            ->where('fecha', '<=', now()->subDays(self::DIAS_PROVEEDOR))
            ->whereHas('detalle', fn ($q) => $q->whereColumn('cantidad_repuesta', '<', 'cantidad'))
            ->with(['compra.proveedor:id,razon_social', 'detalle'])
            ->orderBy('fecha')
            ->get()
            ->map(function (DevolucionCompra $d) {
                $debe = $d->detalle->sum(fn ($l) => (float) $l->cantidad - (float) $l->cantidad_repuesta);

                return [
                    'nivel' => 'aviso',
                    'titulo' => ($d->compra?->proveedor?->razon_social ?? 'El proveedor').' debe '.Config::cantidad($debe).' u.',
                    'detalle' => 'Devolución del '.$d->fecha?->format('d/m').', sin reponer',
                    'url' => route('devoluciones-compra.show', $d),
                ];
            })
            ->all();
    }
}
