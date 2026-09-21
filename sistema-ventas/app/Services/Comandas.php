<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La comanda: el papel de 80 mm que va a la cocina.
 *
 * Existe porque la pantalla de la cocina no alcanza: si no hay pantalla o se
 * cae la red, no quedaba nada en papel. Lleva el número del pedido, comer
 * aquí o para llevar, la hora y cada plato con su nota. Sin precios: a la
 * cocina no le importan. Y solo lo que pasa por la cocina: una gaseosa no se
 * cocina (`pedido_detalle.pasa_por_cocina`).
 *
 * Cada línea recuerda si ya salió en papel (`comandado_en`), así que imprimir
 * de nuevo no repite lo que ya salió: trae solo lo pendiente. En el local los
 * platos entran todos de una vez por el mostrador, así que lo normal es una
 * comanda por pedido. Lo que sí puede pasar después es que se cancele algo que
 * ya estaba en papel —un plato del pedido con el cobro anulado, o el pedido entero—: eso
 * sale en la comanda siguiente como «CANCELADO», una sola vez
 * (`cancelacion_comandada_en`), porque si no el cocinero lo prepara igual.
 *
 * La reimpresión (se perdió el papel) trae la comanda completa, marcada como
 * tal, y no marca nada.
 */
class Comandas
{
    /**
     * Lo que falta mandar a la cocina en papel.
     *
     * @return array{nuevas: Collection<int, PedidoDetalle>, canceladas: Collection<int, PedidoDetalle>}
     */
    public static function pendiente(Pedido $pedido): array
    {
        $lineas = $pedido->detalle()->paraLaCocina()->orderBy('id')->get();
        $cancelado = $pedido->estado === Pedido::CANCELADO;

        return [
            // Lo que nunca salió en papel y todavía hay que hacer. De un pedido
            // cancelado no se manda nada nuevo.
            'nuevas' => $cancelado ? collect() : $lineas
                ->filter(fn (PedidoDetalle $l) => $l->comandado_en === null
                    && $l->estado_cocina !== PedidoDetalle::CANCELADO)
                ->values(),
            // Lo que ya estaba en papel en la cocina y se canceló después —el
            // plato, o el pedido entero—, y todavía no se avisó.
            'canceladas' => $lineas
                ->filter(fn (PedidoDetalle $l) => $l->comandado_en !== null
                    && $l->cancelacion_comandada_en === null
                    && ($cancelado || $l->estado_cocina === PedidoDetalle::CANCELADO))
                ->values(),
        ];
    }

    /** ¿Hay algo que mandar a la cocina en papel? */
    public static function hayPendiente(Pedido $pedido): bool
    {
        $pendiente = self::pendiente($pedido);

        return $pendiente['nuevas']->isNotEmpty() || $pendiente['canceladas']->isNotEmpty();
    }

    /** ¿Alguna vez salió algo de este pedido en papel? */
    public static function yaSalio(Pedido $pedido): bool
    {
        return $pedido->detalle()->whereNotNull('comandado_en')->exists();
    }

    /** ¿El pedido tiene algo que la cocina prepare? Sin eso no hay comanda que imprimir. */
    public static function tieneCocina(Pedido $pedido): bool
    {
        return $pedido->detalle()->paraLaCocina()->exists();
    }

    /**
     * Manda a la cocina lo pendiente: marca esas líneas con la hora de esta
     * comanda y deja el rastro en la bitácora.
     *
     * Con el pedido bloqueado: dos pantallas que imprimen a la vez no pueden
     * mandar dos veces lo mismo. Devuelve la hora que identifica esta comanda,
     * o null si no había nada pendiente.
     */
    public static function imprimir(Pedido $pedido, Usuario $usuario): ?Carbon
    {
        return DB::transaction(function () use ($pedido, $usuario) {
            $actual = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $pendiente = self::pendiente($actual);

            if ($pendiente['nuevas']->isEmpty() && $pendiente['canceladas']->isEmpty()) {
                return null;
            }

            // Al segundo, que es lo que guarda la columna: es con lo que
            // después se vuelve a encontrar esta comanda. Si la anterior de
            // este pedido salió en el mismo segundo (se canceló un plato y se
            // imprimió el aviso enseguida), esta va un segundo después: cada
            // comanda tiene su propia marca y no se mezclan al reimprimirla.
            $marca = now()->startOfSecond();
            $ultima = $actual->detalle()->get(['comandado_en', 'cancelacion_comandada_en'])
                ->flatMap(fn (PedidoDetalle $l) => [$l->comandado_en, $l->cancelacion_comandada_en])
                ->filter()
                ->max();

            if ($ultima && $ultima->greaterThanOrEqualTo($marca)) {
                $marca = $ultima->copy()->addSecond();
            }

            // Por consulta y no con `save()`: imprimir no es un cambio de la
            // cocina, y `actualizado_en` —la hora del último cambio de estado—
            // se queda como estaba.
            PedidoDetalle::whereKey($pendiente['nuevas']->pluck('id'))
                ->update(['comandado_en' => $marca, 'actualizado_en' => DB::raw('actualizado_en')]);
            PedidoDetalle::whereKey($pendiente['canceladas']->pluck('id'))
                ->update(['cancelacion_comandada_en' => $marca, 'actualizado_en' => DB::raw('actualizado_en')]);

            Auditor::registrar('COMANDA_IMPRESA', 'pedidos', $actual->id, [
                'numero' => $actual->numero_dia,
                'lineas' => $pendiente['nuevas']->count(),
                'canceladas' => $pendiente['canceladas']->count(),
                'reimpresion' => false,
            ], $usuario->id);

            return $marca;
        });
    }

    /** La reimpresión: no marca nada, pero queda en la bitácora quién la pidió. */
    public static function reimprimir(Pedido $pedido, Usuario $usuario): void
    {
        Auditor::registrar('COMANDA_IMPRESA', 'pedidos', $pedido->id, [
            'numero' => $pedido->numero_dia,
            'lineas' => self::completa($pedido)['nuevas']->count(),
            'canceladas' => 0,
            'reimpresion' => true,
        ], $usuario->id);
    }

    /**
     * Lo que salió en la comanda de esa hora: las líneas que se mandaron y
     * las cancelaciones que se avisaron.
     *
     * @return array{nuevas: Collection<int, PedidoDetalle>, canceladas: Collection<int, PedidoDetalle>}
     */
    public static function deLaMarca(Pedido $pedido, Carbon $marca): array
    {
        $lineas = $pedido->detalle()->paraLaCocina()->orderBy('id')->get();

        return [
            'nuevas' => $lineas->filter(fn (PedidoDetalle $l) => $l->comandado_en?->equalTo($marca))->values(),
            'canceladas' => $lineas->filter(fn (PedidoDetalle $l) => $l->cancelacion_comandada_en?->equalTo($marca))->values(),
        ];
    }

    /**
     * La comanda completa, como está ahora: lo que la cocina tiene que hacer
     * de este pedido. Lo cancelado no va —ya no hay que hacerlo—, salvo que se
     * haya cancelado el pedido entero, que se imprime para que se vea.
     *
     * @return array{nuevas: Collection<int, PedidoDetalle>, canceladas: Collection<int, PedidoDetalle>}
     */
    public static function completa(Pedido $pedido): array
    {
        $lineas = $pedido->detalle()->paraLaCocina()->orderBy('id')->get();

        if ($pedido->estado === Pedido::CANCELADO) {
            return ['nuevas' => collect(), 'canceladas' => $lineas->where('estado_cocina', '<>', PedidoDetalle::CANCELADO)->values()];
        }

        return [
            'nuevas' => $lineas->where('estado_cocina', '<>', PedidoDetalle::CANCELADO)->values(),
            'canceladas' => collect(),
        ];
    }
}
