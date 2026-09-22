{{-- La campana y su desplegable. La usan la cabecera al pintar la página y
     NotificacionController al refrescarla: una sola plantilla. `abierto` es
     del envoltorio (header/notificaciones). --}}
@php
    $total = $avisos['total'] ?? 0;
    $punto = fn (string $nivel) => match ($nivel) {
        'peligro' => 'bg-error-500',
        'aviso' => 'bg-warning-500',
        default => 'bg-gray-400 dark:bg-gray-500',
    };
@endphp

@if ($avisos !== null)
    <button type="button" @click="abierto = !abierto" :aria-expanded="abierto" aria-controls="notificaciones"
        aria-label="{{ $total === 0 ? 'Avisos: nada pendiente' : "Avisos: {$total} pendiente(s)" }}"
        data-notificaciones="{{ $total }}"
        class="relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M6 9.5a6 6 0 1 1 12 0c0 3.2.9 5.3 1.8 6.6.3.4 0 .9-.5.9H4.7c-.5 0-.8-.5-.5-.9C5.1 14.8 6 12.7 6 9.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
            <path d="M9.75 20a2.5 2.5 0 0 0 4.5 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </svg>
        @if ($total > 0)
            <span aria-hidden="true"
                class="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-semibold leading-none text-white tabular-nums {{ $avisos['grave'] ? 'bg-error-500' : 'bg-warning-500' }}">
                {{ $total > 99 ? '99+' : $total }}
            </span>
        @endif
    </button>

    <div id="notificaciones" x-show="abierto" x-cloak x-transition.opacity.duration.150ms
        class="absolute right-0 z-50 mt-[17px] flex w-[340px] max-w-[calc(100vw-2.5rem)] flex-col rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">Avisos</span>
            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                {{ $total === 0 ? 'Nada pendiente: todo en orden.' : 'Se van solos cuando se resuelven.' }}
            </span>
        </div>

        @if ($total > 0)
            <div class="max-h-[26rem] overflow-y-auto overscroll-contain">
                @foreach ($avisos['grupos'] as $clave => $grupo)
                    <section @if ($clave === 'inventario') data-alertas-stock="{{ $grupo['stock'] }}" @endif data-grupo-avisos="{{ $clave }}">
                        <p class="bg-gray-50 px-4 py-1.5 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                            {{ $grupo['titulo'] }}
                        </p>
                        <ul>
                            @foreach (array_slice($grupo['avisos'], 0, \App\Support\Notificaciones::MOSTRAR) as $aviso)
                                <li>
                                    <{{ $aviso['url'] ? 'a' : 'div' }} @if ($aviso['url']) href="{{ $aviso['url'] }}" @endif data-aviso="{{ $aviso['nivel'] }}"
                                        class="flex items-start gap-3 border-b border-gray-100 px-4 py-2.5 last:border-0 dark:border-gray-800 {{ $aviso['url'] ? 'transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' : '' }}">
                                        <span class="mt-1.5 h-2 w-2 flex-none rounded-full {{ $punto($aviso['nivel']) }}" aria-hidden="true"></span>
                                        <span class="min-w-0">
                                            <span class="block text-theme-sm text-gray-800 dark:text-white/90">{{ $aviso['titulo'] }}</span>
                                            @if ($aviso['detalle'])
                                                <span class="block truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $aviso['detalle'] }}</span>
                                            @endif
                                        </span>
                                    </{{ $aviso['url'] ? 'a' : 'div' }}>
                                </li>
                            @endforeach
                        </ul>
                        @if ($clave === 'inventario' && $grupo['stock'] > 0)
                            <a href="{{ route('compras.sugerida') }}" class="block px-4 py-2 text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                Armar la compra sugerida{{ $grupo['stock'] > \App\Support\AlertasStock::MOSTRAR ? " ({$grupo['stock']} productos)" : '' }}
                            </a>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endif
