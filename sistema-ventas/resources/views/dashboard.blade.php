@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();

    $grafico = $serie ? [
        'tipo' => 'area',
        'moneda' => $moneda,
        'categorias' => array_column($serie, 'etiqueta'),
        'series' => [['name' => 'Vendido', 'data' => array_column($serie, 'monto')]],
        'alto' => 280,
    ] : null;
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Saludo --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                        Hola, {{ $usuario->empleado?->nombres ?? $usuario->usuario }}
                    </h2>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ ucfirst(now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')) }} ·
                        {{ $usuario->empleado?->cargo?->nombre }}, rol {{ $usuario->rol?->nombre }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @puede('ventas.registrar')
                        <x-ui.button size="sm" :href="route('pos.index')">Ir al mostrador</x-ui.button>
                    @endpuede
                    @puede('reportes.ver')
                        <x-ui.button size="sm" variant="outline" :href="route('reportes.ventas')">
                            Ver reportes
                        </x-ui.button>
                    @endpuede
                </div>
            </div>
        </div>

        {{-- Mi turno --}}
        @if ($sesion || $mias)
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Mi turno
                            </p>
                            @if ($sesion)
                                <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                    {{ $sesion->caja?->nombre }}
                                </p>
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Abierto a las {{ $sesion->fecha_apertura?->format('H:i') }} ·
                                    {{ $sesion->ventas_count }} venta(s) ·
                                    inicial {{ Config::importe($sesion->monto_inicial) }}
                                </p>
                            @else
                                <p class="text-lg font-semibold text-gray-800 dark:text-white/90">Sin caja abierta</p>
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Cada venta se imputa a un turno. Abre el tuyo para empezar a cobrar.
                                </p>
                            @endif
                        </div>

                        @if ($sesion)
                            <div class="text-right">
                                <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Efectivo esperado
                                </p>
                                <p class="text-title-sm font-semibold text-brand-500 dark:text-brand-400">
                                    {{ Config::importe($sesion->efectivoEsperado()) }}
                                </p>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($sesion)
                            <x-ui.button size="xs" variant="outline" :href="route('caja.show', $sesion)">
                                Ver turno y cerrar
                            </x-ui.button>
                        @else
                            @puede('caja.abrir')
                                <x-ui.button size="xs" :href="route('caja.index')">Abrir caja</x-ui.button>
                            @endpuede
                        @endif
                    </div>
                </div>

                @if ($mias)
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Lo que llevo vendido hoy
                        </p>
                        <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ Config::importe($mias['monto']) }}
                        </p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $mias['operaciones'] }} operación(es) a mi nombre
                        </p>
                    </div>
                @endif
            </div>
        @endif

        {{-- El negocio hoy --}}
        @if ($hoy)
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @php
                    $variacion = $hoy['variacion'];

                    $tarjetas = [
                        ['Vendido hoy', Config::importe($hoy['hoy']['monto']), 'text-gray-800 dark:text-white/90',
                            'ayer ' . Config::importe($hoy['ayer']['monto'])],
                        ['Operaciones', number_format($hoy['hoy']['operaciones']), 'text-gray-800 dark:text-white/90',
                            'ayer ' . number_format($hoy['ayer']['operaciones'])],
                        ['Ticket promedio', Config::importe($hoy['hoy']['ticket']), 'text-gray-800 dark:text-white/90',
                            'ayer ' . Config::importe($hoy['ayer']['ticket'])],
                    ];
                @endphp

                @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $etiqueta }}
                        </p>
                        <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    </div>
                @endforeach

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Frente a ayer
                    </p>
                    @if ($variacion === null)
                        <p class="text-title-sm font-semibold text-gray-500 dark:text-gray-400">—</p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">ayer no hubo ventas</p>
                    @else
                        <p class="text-title-sm font-semibold {{ $variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                            {{ $variacion > 0 ? '+' : '' }}{{ number_format($variacion, 1) }}%
                        </p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">sobre lo vendido ayer</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Evolución --}}
        @if ($grafico)
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                    <div>
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Últimas dos semanas</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Vendido por día, sin contar las ventas anuladas.
                        </p>
                    </div>
                    <x-ui.button size="xs" variant="outline" :href="route('reportes.ventas')">
                        Reporte completo
                    </x-ui.button>
                </div>
                <div class="px-3 pb-3">
                    <div data-apexchart="{{ json_encode($grafico) }}"></div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Últimas ventas --}}
            @if ($ultimas)
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Últimas ventas</h2>
                        <x-ui.button size="xs" variant="outline" :href="route('ventas.index')">Ver todas</x-ui.button>
                    </div>

                    <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($ultimas as $venta)
                                    <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                        <td class="px-6 py-3">
                                            <a href="{{ route('ventas.show', $venta) }}"
                                                class="block font-mono text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                                {{ $venta->comprobante?->numero_completo ?? '#'.$venta->id }}
                                            </a>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $venta->fecha?->format('d/m H:i') }} · {{ $venta->usuario?->usuario }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $venta->cliente?->nombre ?? 'Cliente varios' }}
                                        </td>
                                        <td class="px-6 py-3">
                                            @if ($venta->estado !== 'COMPLETADA')
                                                <x-ui.estado
                                                    :estado="$venta->estado === 'ANULADA' ? 'CESADO' : 'SUSPENDIDO'"
                                                    :texto="ucfirst(str_replace('_', ' ', mb_strtolower($venta->estado)))" />
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 text-right whitespace-nowrap text-theme-sm font-medium {{ $venta->estado === 'ANULADA' ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-white/90' }}">
                                            {{ Config::importe($venta->total) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                            Todavía no se registró ninguna venta.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Alertas de reposición --}}
            @if ($alertas !== null)
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] {{ $ultimas ? '' : 'lg:col-span-3' }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                        <div>
                            <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Reponer</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Productos en su stock mínimo.
                            </p>
                        </div>
                        @if ($alertas->isNotEmpty())
                            <x-ui.estado estado="SUSPENDIDO" :texto="$alertasTotal" />
                        @endif
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800">
                        @forelse ($alertas as $alerta)
                            <a href="{{ route('productos.show', $alerta->id) }}"
                                class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-3 transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]">
                                <div class="min-w-0">
                                    <span class="block truncate text-theme-sm text-gray-800 dark:text-white/90">
                                        {{ $alerta->nombre }}
                                    </span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $alerta->categoria }}
                                    </span>
                                </div>
                                <span class="whitespace-nowrap text-theme-sm font-medium {{ (float) $alerta->stock_actual <= 0 ? 'text-error-600 dark:text-error-400' : 'text-warning-700 dark:text-orange-400' }}">
                                    {{ Config::cantidad($alerta->stock_actual) }} / {{ Config::cantidad($alerta->stock_minimo) }}
                                </span>
                            </a>
                        @empty
                            <p class="px-6 py-10 text-center text-theme-sm text-success-700 dark:text-success-500">
                                Nada por reponer.
                            </p>
                        @endforelse
                    </div>

                    @if ($gestion && $alertas->isNotEmpty())
                        <div class="px-6 py-4">
                            <x-ui.button size="xs" variant="outline" class="w-full"
                                :href="route('reportes.productos')">
                                Ver todas las alertas
                            </x-ui.button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
