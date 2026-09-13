@php
    use App\Support\AlertasStock;
    use App\Support\Config;

    $usuario = auth()->user();
    $alertas = AlertasStock::para($usuario);

    if ($alertas !== null) {
        // El inventario filtrado es la lista completa, ordenada por lo que más
        // falta. Quien no entra al inventario pero gestiona el catálogo va a la
        // ficha del producto.
        $veInventario = $usuario->tienePermiso('inventario.ingresar')
            || $usuario->tienePermiso('inventario.ajustar')
            || $usuario->tienePermiso('reportes.ver');

        $enlace = fn (object $p) => $veInventario
            ? route('inventario.index', ['buscar' => $p->codigo])
            : route('productos.show', $p->id);
    }
@endphp

@if ($alertas !== null)
    <div class="relative" x-data="{ abierto: false }" @click.away="abierto = false" @keydown.escape="abierto = false"
        data-alertas-stock="{{ $alertas['total'] }}">
        <button type="button" @click="abierto = !abierto" :aria-expanded="abierto" aria-controls="alertas-stock"
            aria-label="{{ $alertas['total'] === 0 ? 'Alertas de stock: nada por reponer' : "Alertas de stock: {$alertas['total']} producto(s) por reponer" }}"
            class="relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 9.5a6 6 0 1 1 12 0c0 3.2.9 5.3 1.8 6.6.3.4 0 .9-.5.9H4.7c-.5 0-.8-.5-.5-.9C5.1 14.8 6 12.7 6 9.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                <path d="M9.75 20a2.5 2.5 0 0 0 4.5 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
            @if ($alertas['total'] > 0)
                <span aria-hidden="true"
                    class="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-semibold leading-none text-white tabular-nums {{ $alertas['agotados'] > 0 ? 'bg-error-500' : 'bg-warning-500' }}">
                    {{ $alertas['total'] > 99 ? '99+' : $alertas['total'] }}
                </span>
            @endif
        </button>

        <div id="alertas-stock" x-show="abierto" x-cloak x-transition.opacity.duration.150ms
            class="absolute right-0 z-50 mt-[17px] flex w-[320px] max-w-[calc(100vw-2.5rem)] flex-col rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">Por reponer</span>
                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                    @if ($alertas['total'] === 0)
                        Todo está sobre su stock mínimo.
                    @else
                        {{ $alertas['total'] }} en su mínimo o por debajo{{ $alertas['agotados'] > 0 ? ", {$alertas['agotados']} agotado(s)" : '' }}.
                    @endif
                </span>
            </div>

            @if ($alertas['total'] > 0)
                <ul class="max-h-80 overflow-y-auto overscroll-contain">
                    @foreach ($alertas['productos'] as $producto)
                        @php $agotado = (float) $producto->stock_actual <= 0; @endphp
                        <li>
                            <a href="{{ $enlace($producto) }}"
                                class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-2.5 transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                <span class="min-w-0">
                                    <span class="block truncate text-theme-sm text-gray-800 dark:text-white/90">{{ $producto->nombre }}</span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $producto->categoria }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block text-theme-sm font-medium tabular-nums {{ $agotado ? 'text-error-600 dark:text-error-400' : 'text-warning-700 dark:text-orange-400' }}">
                                        {{ $agotado ? 'Agotado' : Config::cantidad($producto->stock_actual) }}
                                    </span>
                                    <span class="text-theme-xs tabular-nums text-gray-500 dark:text-gray-400">mín. {{ Config::cantidad($producto->stock_minimo) }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($veInventario)
                    <a href="{{ route('inventario.index', ['stock' => 'BAJO', 'orden' => 'faltante', 'dir' => 'desc']) }}"
                        class="border-t border-gray-100 px-4 py-3 text-center text-theme-sm font-medium text-brand-500 hover:text-brand-600 dark:border-gray-800 dark:text-brand-400">
                        {{ $alertas['total'] > AlertasStock::MOSTRAR ? "Ver los {$alertas['total']}" : 'Ver en el inventario' }}
                    </a>
                @endif
            @endif
        </div>
    </div>
@endif
