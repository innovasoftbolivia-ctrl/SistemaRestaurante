@php
    use App\Models\Pedido;
    use App\Models\PedidoDetalle;
    use App\Services\Comandas;
    use App\Support\Config;

    $pedido = $tanda['pedido'];
    $listo = $tanda['listo'];
    $llevar = $pedido->tipo === Pedido::LLEVAR;
    $desde = $tanda['desde']?->getTimestampMs() ?? now()->getTimestampMs();
    $porImprimir = Comandas::hayPendiente($pedido);
@endphp

{{-- Un pedido del tablero. Arriba el número (lo que se canta), el destino y el
     reloj; en medio sus platos, cada uno tocable por separado; abajo UN botón
     que lo lleva entero a la columna siguiente, y la comanda en papel. --}}
<article data-pedido-cocina="{{ $pedido->id }}" @if ($listo) data-listo @endif
    aria-label="{{ $pedido->numero_visible }} · {{ $pedido->destino }}{{ $listo ? ' · listo' : '' }}"
    class="overflow-hidden rounded-xl border-2 bg-white dark:bg-gray-900 {{ match ($columna) {
        'hacer' => 'border-gray-200 dark:border-gray-800',
        'cocinando' => 'border-warning-300 dark:border-warning-500/40',
        default => 'border-success-500',
    } }}">
    <header class="flex items-start justify-between gap-3 px-4 pt-3 pb-2">
        <div class="min-w-0">
            <p class="sr-only">Pedido</p>
            <p class="font-mono text-5xl font-black leading-none text-gray-900 dark:text-white"
                data-numero-pedido>{{ $pedido->numero_dia }}</p>
            <p class="mt-1.5 text-theme-sm font-bold uppercase tracking-wide {{ $llevar ? 'text-warning-700 dark:text-orange-400' : 'text-brand-600 dark:text-brand-400' }}">
                {{ $pedido->destino }}
            </p>
            @if ($pedido->quien)
                <p class="truncate text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $pedido->quien }}</p>
            @endif
        </div>

        <div class="flex flex-none flex-col items-end gap-1.5 text-right">
            {{-- Cuánto lleva esperando: ámbar a los 10 minutos, rojo a los 20. --}}
            <span data-reloj="{{ $desde }}"
                class="rounded-lg px-2.5 py-1 font-mono text-theme-sm font-semibold"
                :class="colorEspera({{ $desde }})"
                x-text="espera({{ $desde }})">{{ (int) floor(max(0, now()->getTimestampMs() - $desde) / 60000) }} min</span>
            <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                {{ $tanda['desde']?->format('H:i') }}
                @if ($pedido->usuario?->usuario)
                    · {{ $pedido->usuario->usuario }}
                @endif
            </span>
            {{-- Todos pagaron al pedir. La excepción es uno cuyo cobro se
                 anuló (forma de pago equivocada): se vuelve a cobrar. --}}
            @if ($pedido->estaAbierto())
                <span class="rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-orange-400">Cobro anulado</span>
            @endif
        </div>
    </header>

    <ul class="border-t border-gray-100 dark:border-gray-800">
        @foreach ($tanda['lineas'] as $linea)
            @php
                $siguiente = $linea->siguiente_estado;
                $enPalabras = match ($siguiente) {
                    PedidoDetalle::EN_PREPARACION => 'en preparación',
                    PedidoDetalle::LISTO => 'listo',
                    default => 'entregado',
                };
            @endphp
            <li class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                {{-- El plato suelto: tocarlo lo avanza solo a él. Sin
                     «Cancelar»: cancelar es dejarlo sin cobrar, y eso lo decide
                     la caja. --}}
                <form method="POST" action="{{ route('cocina.estado', $linea) }}">
                    @csrf
                    <input type="hidden" name="estado" value="{{ $siguiente }}" />
                    <button type="submit" @disabled(! $siguiente)
                        aria-label="{{ Config::cantidad($linea->cantidad) }} × {{ $linea->descripcion }}: {{ mb_strtolower($linea->estado_visible) }}. Marcar como {{ $enPalabras }}"
                        title="Toca para marcarlo {{ $enPalabras }}"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/[0.03] dark:focus-visible:bg-white/[0.03]">
                        <span aria-hidden="true" class="flex h-7 w-7 flex-none items-center justify-center rounded-full {{ match ($linea->estado_cocina) {
                            PedidoDetalle::EN_PREPARACION => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-orange-400',
                            PedidoDetalle::LISTO => 'bg-success-500 text-white',
                            default => 'border-2 border-gray-300 dark:border-gray-600',
                        } }}">
                            @if ($linea->estado_cocina === PedidoDetalle::EN_PREPARACION)
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            @elseif ($linea->estado_cocina === PedidoDetalle::LISTO)
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
                            @endif
                        </span>
                        <span class="min-w-0 flex-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ Config::cantidad($linea->cantidad) }} × {{ $linea->descripcion }}
                        </span>
                        <span class="flex-none text-theme-xs text-gray-400 dark:text-gray-500">{{ $linea->estado_visible }}</span>
                    </button>
                </form>

                {{-- La nota es lo que más se equivoca la cocina si no la ve: en
                     su propio recuadro y en grande. --}}
                @if ($linea->nota)
                    <p class="mx-4 mb-2.5 -mt-0.5 rounded-lg bg-brand-50 px-3 py-1.5 text-base font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-400">
                        {{ $linea->nota }}
                    </p>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($listo)
        <p class="border-t border-success-200 bg-success-50 px-4 py-2 text-center text-base font-bold text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-500">
            ¡Listo! Canta el {{ $pedido->numero_dia }}
        </p>
    @endif

    <footer class="flex items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
        {{-- El botón del pedido entero: lo pasa a la columna siguiente. --}}
        <form method="POST" class="flex-1"
            action="{{ $columna === 'entregar' ? route('cocina.entregar', $pedido) : route('cocina.avanzar', $pedido) }}">
            @csrf
            @unEnvio
            @if ($columna !== 'entregar')
                <input type="hidden" name="estado"
                    value="{{ $columna === 'hacer' ? PedidoDetalle::EN_PREPARACION : PedidoDetalle::LISTO }}" />
            @endif
            <button type="submit" data-avanzar-pedido="{{ $columna }}"
                class="min-h-12 w-full rounded-xl px-4 text-base font-semibold transition focus-visible:outline-none focus-visible:ring-3 {{ match ($columna) {
                    'hacer' => 'bg-brand-500 text-white hover:bg-brand-600 focus-visible:ring-brand-500/40',
                    'cocinando' => 'bg-warning-400 text-warning-950 hover:bg-warning-300 focus-visible:ring-warning-500/40',
                    default => 'bg-success-600 text-white hover:bg-success-700 focus-visible:ring-success-500/40',
                } }}">
                {{ match ($columna) {
                    'hacer' => 'Empezar',
                    'cocinando' => 'Listo',
                    default => 'Entregado',
                } }}
            </button>
        </form>

        {{-- La comanda en papel, por si hace falta: la de lo que todavía no
             salió, o de nuevo la completa si se perdió. --}}
        <form method="POST" target="_blank"
            action="{{ route($porImprimir ? 'pedidos.comanda.imprimir' : 'pedidos.comanda.reimprimir', $pedido) }}">
            @csrf
            <button type="submit" data-comanda-boton="{{ $porImprimir ? 'imprimir' : 'reimprimir' }}"
                title="{{ $porImprimir ? 'Imprimir comanda' : 'Reimprimir comanda' }}"
                class="flex min-h-12 items-center gap-1.5 rounded-xl border px-3 text-theme-sm font-medium transition {{ $porImprimir
                    ? 'border-brand-300 text-brand-600 hover:bg-brand-50 dark:border-brand-500/40 dark:text-brand-400 dark:hover:bg-brand-500/10'
                    : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]' }}">
                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7z"/></svg>
                <span class="sr-only">{{ $porImprimir ? 'Imprimir comanda' : 'Reimprimir comanda' }}</span>
                <span aria-hidden="true">{{ $porImprimir ? 'Imprimir' : 'Reimprimir' }}</span>
            </button>
        </form>
    </footer>
</article>
