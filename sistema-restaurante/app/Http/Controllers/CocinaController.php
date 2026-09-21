<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\Pedidos;
use App\Support\Config;
use App\Support\Mensaje;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * La pantalla de la cocina: lo que falta preparar, pedido por pedido.
 *
 * La usa la cocina y también quien lleva los platos: por eso cada pedido se
 * lee por su NÚMERO, en grande —es lo que se canta al entregar y lo que el
 * cliente tiene en su ticket—, con «comer aquí» o «para llevar» al lado.
 *
 * Es un tablero de tres columnas —por hacer, cocinando, para entregar— y el
 * pedido camina de izquierda a derecha con un solo botón (`avanzar`); quien
 * lleva los platos solo mira la última. Tocar un plato suelto lo avanza solo a
 * él, para cuando uno sale antes que los demás.
 *
 * Se refresca sola cada pocos segundos pidiendo el mismo listado en JSON. Es
 * un sondeo y no un WebSocket a propósito: el local tiene una pantalla en la
 * cocina y nada más, y montar un servidor de eventos para eso habría sumado
 * una pieza más que mantener en un hosting donde ni los procedimientos de
 * MySQL se pueden dar por seguros.
 */
class CocinaController extends Controller
{
    /** Cada cuánto vuelve a preguntar la pantalla. */
    public const SEGUNDOS_REFRESCO = 10;

    /** Minutos de espera desde los que el reloj del pedido se pone ámbar, y rojo. */
    public const MINUTOS_AVISO = 10;

    public const MINUTOS_TARDE = 20;

    /** Las columnas del tablero, en el orden en que el pedido las recorre. */
    public const COLUMNAS = [
        'hacer' => 'Por hacer',
        'cocinando' => 'Cocinando',
        'entregar' => 'Para entregar',
    ];

    public function index(): View
    {
        $tandas = $this->tandas();

        return view('cocina.index', [
            'title' => 'Cocina',
            'tandas' => $tandas,
            'columnas' => collect(self::COLUMNAS)->map(fn (string $titulo, string $clave) => [
                'titulo' => $titulo,
                'tandas' => $tandas->where('columna', $clave)->values(),
            ]),
            'segundos' => self::SEGUNDOS_REFRESCO,
            'minutosAviso' => self::MINUTOS_AVISO,
            'minutosTarde' => self::MINUTOS_TARDE,
            'soloEntrega' => self::soloEntrega(),
        ]);
    }

    /**
     * Quien ve la cocina para llevar los platos (`cocina.entregar`) y no para
     * cocinarlos (`cocina.ver`): entrega lo listo, no mueve la preparación.
     */
    public static function soloEntrega(): bool
    {
        $usuario = Auth::user();

        return $usuario !== null && ! $usuario->tienePermiso('cocina.ver');
    }

    /** El mismo listado, para el sondeo de la pantalla. */
    public function pendientes(): JsonResponse
    {
        return response()->json([
            // Sirve para que la pantalla sepa si algo cambió sin comparar todo.
            'actualizado' => now()->toIso8601String(),
            'tandas' => $this->tandas()->map(fn (array $tanda) => [
                'pedido_id' => $tanda['pedido']->id,
                // El número que se canta: lo que la pantalla muestra en grande.
                'numero' => $tanda['pedido']->numero_dia,
                'destino' => $tanda['pedido']->destino,
                'etiqueta' => $tanda['pedido']->etiqueta,
                'tipo' => $tanda['pedido']->tipo,
                'atendio' => $tanda['pedido']->usuario?->usuario,
                'cobrado' => $tanda['pedido']->estado === Pedido::CERRADO,
                // Todo lo del pedido está listo: hay que cantarlo.
                'listo' => $tanda['listo'],
                'columna' => $tanda['columna'],
                'lineas' => $tanda['lineas']->map(fn (PedidoDetalle $l) => [
                    'id' => $l->id,
                    'descripcion' => $l->descripcion,
                    'cantidad' => (float) $l->cantidad,
                    'nota' => $l->nota,
                    'estado' => $l->estado_cocina,
                    'estado_visible' => $l->estado_visible,
                    'siguiente' => $l->siguiente_estado,
                    // La hora de pedido, que es lo que ordena la cocina.
                    'hora' => $l->creado_en?->format('H:i'),
                    'minutos' => $l->creado_en ? (int) $l->creado_en->diffInMinutes(now()) : 0,
                ])->values(),
            ])->values(),
        ]);
    }

    public function actualizarEstado(Request $request, PedidoDetalle $linea): RedirectResponse
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(PedidoDetalle::PASOS_DE_COCINA)],
        ], [
            'estado.in' => 'La cocina solo avanza la preparación: cancelar un plato se hace desde la caja.',
        ]);

        // Solo lo que está en la pantalla: lo que pasa por la cocina, de la
        // jornada en curso. Sin esto, un POST armado a mano llevaba una bebida
        // a «entregado», y el pedido quedaba con platos «empezados» que la
        // caja ya no podía cancelar sin administrador.
        if (! $linea->pasa_por_cocina || ! $this->esDeLaJornada($linea->pedido)) {
            return back()->with('error', 'Ese plato no está en la pantalla de la cocina.');
        }

        // Quien lleva los platos solo entrega lo que la cocina ya terminó.
        abort_if(self::soloEntrega() && ($datos['estado'] !== PedidoDetalle::ENTREGADO
            || $linea->estado_cocina !== PedidoDetalle::LISTO), 403, 'Solo puedes marcar entregado lo que la cocina ya dejó listo.');

        try {
            Pedidos::actualizarEstadoLinea($linea, $datos['estado'], Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return back()->with('exito', "«{$linea->descripcion}»: {$linea->fresh()->estado_visible}.");
    }

    /**
     * El botón del pedido: «Empezar» (todo a EN_PREPARACION) o «Listo» (todo a
     * LISTO). Entregar tiene su propia ruta, la de abajo.
     */
    public function avanzar(Request $request, Pedido $pedido): RedirectResponse
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO])],
        ], [
            'estado.in' => 'La cocina solo avanza la preparación: cancelar un plato se hace desde la caja.',
        ]);

        if (! $this->esDeLaJornada($pedido)) {
            return back()->with('error', "{$pedido->numero_visible} no está en la pantalla de la cocina.");
        }

        try {
            $cuantos = Pedidos::avanzarTodo($pedido, $datos['estado'], Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return back()->with('exito', match (true) {
            $cuantos === 0 => "{$pedido->numero_visible} no tenía platos para avanzar.",
            $datos['estado'] === PedidoDetalle::LISTO => "{$pedido->numero_visible} listo: a cantarlo.",
            default => "{$pedido->numero_visible} en preparación.",
        });
    }

    /**
     * El pedido salió entero: todo lo que estaba LISTO pasa a ENTREGADO de un
     * toque. Es el botón de quien lleva los platos: canta el número, el
     * cliente muestra su ticket y se lo entrega.
     */
    public function entregar(Pedido $pedido): RedirectResponse
    {
        if (! $this->esDeLaJornada($pedido)) {
            return back()->with('error', "{$pedido->numero_visible} no está en la pantalla de la cocina.");
        }

        try {
            $entregados = Pedidos::entregarLoListo($pedido, Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return back()->with('exito', $entregados > 0
            ? "{$pedido->numero_visible} entregado."
            : "{$pedido->numero_visible} no tenía nada listo para entregar.");
    }

    /** La pantalla muestra solo la jornada en curso: lo de ayer no se mueve desde aquí. */
    private function esDeLaJornada(?Pedido $pedido): bool
    {
        return $pedido?->jornada?->toDateString() === Config::jornadaActual();
    }

    /**
     * Lo que la cocina tiene por delante, agrupado por pedido.
     *
     * Se muestran también las líneas LISTO: alguien tiene que llevarlas, y
     * hasta que se marcan ENTREGADO la cocina es quien las ve.
     *
     * Manda el estado del plato, no el del pedido: el pedido del mostrador se
     * cobra al pedirlo, y sus platos siguen por hacer. Solo quedan fuera los
     * de un pedido cancelado, que nadie va a comer, y lo que no pasa por la
     * cocina (las bebidas: `pedido_detalle.pasa_por_cocina`).
     *
     * Y solo los de la jornada en curso (`Config::jornadaActual`): un plato
     * que nadie marcó como entregado se quedaba en pantalla para siempre, y
     * los de ayer ya no los va a preparar nadie. La jornada cambia a la hora
     * de corte, con el local cerrado.
     *
     * Cada pedido va a una columna del tablero: «para entregar» si todo está
     * listo, «por hacer» si la cocina no tocó nada, y «cocinando» en cualquier
     * otro caso —también si un plato ya salió y otro sigue sin empezar—.
     *
     * @return Collection<int, array{pedido: Pedido, lineas: Collection<int, PedidoDetalle>, listo: bool, columna: string, desde: ?CarbonInterface}>
     */
    private function tandas(): Collection
    {
        $jornada = Config::jornadaActual();

        $lineas = PedidoDetalle::query()
            ->paraLaCocina()
            ->whereIn('estado_cocina', [...PedidoDetalle::EN_COCINA, PedidoDetalle::LISTO])
            ->whereHas('pedido', fn ($q) => $q
                ->where('estado', '<>', Pedido::CANCELADO)
                ->where('jornada', $jornada))
            ->with(['pedido.usuario:id,usuario', 'pedido.ultimaVenta.cliente:id,nombre'])
            ->orderBy('creado_en')
            ->orderBy('id')
            ->get();

        return $lineas
            ->groupBy('pedido_id')
            ->map(function (Collection $grupo) {
                $listo = $grupo->every(fn (PedidoDetalle $l) => $l->estado_cocina === PedidoDetalle::LISTO);

                return [
                    'pedido' => $grupo->first()->pedido,
                    'lineas' => $grupo,
                    'listo' => $listo,
                    'columna' => match (true) {
                        $listo => 'entregar',
                        $grupo->every(fn (PedidoDetalle $l) => $l->estado_cocina === PedidoDetalle::PENDIENTE) => 'hacer',
                        default => 'cocinando',
                    },
                    // Desde cuándo espera: el plato más viejo que sigue en pantalla.
                    'desde' => $grupo->min('creado_en'),
                ];
            })
            // El pedido con el plato más viejo pendiente, primero: es el que
            // lleva más tiempo esperando.
            ->sortBy(fn (array $tanda) => $tanda['lineas']->first()->creado_en)
            ->values();
    }
}
