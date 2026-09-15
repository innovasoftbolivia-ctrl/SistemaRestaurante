@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();

    $grafico = [
        'tipo' => 'area',
        'moneda' => $moneda,
        'categorias' => array_column($porDia, 'etiqueta'),
        'series' => [['name' => 'Vendido', 'data' => array_column($porDia, 'monto')]],
        'alto' => 320,
    ];

    $graficoMetodos = [
        'tipo' => 'donut',
        'moneda' => $moneda,
        'etiquetas' => $porMetodo->pluck('metodo_pago')->all(),
        'series' => $porMetodo->pluck('monto')->map(fn ($m) => (float) $m)->all(),
        'leyenda' => true,
        'alto' => 300,
    ];
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.ventas')" :excel="route('reportes.ventas.excel')" :pdf="route('reportes.ventas.pdf')" :desde="$desde" :hasta="$hasta" />

        {{-- La frase responde lo que de verdad se pregunta un dueño de
             negocio ("¿cómo me fue?"), antes que la grilla de cifras sueltas.
             La ganancia va sin impuesto y con el costo del día de cada venta
             (las ventas anteriores al 15/09/2026 quedaron con el costo que
             tenía el producto ese 15/09). Antes descontaba el costo de HOY de cada
             producto vendido, no el que tenía el día de la venta (misma
             salvedad que ya lleva el "margen estimado" de Productos). --}}
        <div class="rounded-2xl border border-brand-200 bg-brand-50 p-6 dark:border-brand-800 dark:bg-brand-500/10">
            @php
                // `diffInDays` entre un `startOfDay` y un `endOfDay` no cae en un
                // entero exacto (23:59:59.999999 de por medio) y deja un
                // "30.999999999988 días" en pantalla si no se redondea a día.
                $diasPeriodo = (int) $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
            @endphp
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}
                ({{ $diasPeriodo }} {{ $diasPeriodo === 1 ? 'día' : 'días' }})
            </p>
            <p class="mt-1 text-title-md font-bold text-gray-800 dark:text-white/90">
                Vendiste <span class="text-brand-600 dark:text-brand-400">{{ Config::importe($resumen['vendido']) }}</span>
                y ganaste aproximadamente <span class="text-success-600 dark:text-success-500">{{ Config::importe($resumen['ganancia']) }}</span>
            </p>
            @if ($variacion !== null)
                <p class="mt-2 flex items-center gap-1.5 text-theme-sm {{ $variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        @if ($variacion >= 0)
                            <path d="M12 19V5M12 5l-6 6M12 5l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @else
                            <path d="M12 5v14M12 19l-6-6M12 19l6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @endif
                    </svg>
                    {{ number_format(abs($variacion), 1) }}% {{ $variacion >= 0 ? 'más' : 'menos' }} que el período anterior
                </p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Operaciones</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($resumen['operaciones']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">ventas cobradas</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ticket promedio</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ Config::importe($resumen['ticket']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">por venta</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Devoluciones</p>
                <p class="text-title-sm font-semibold text-error-600 dark:text-error-400">{{ Config::importe($resumen['devuelto']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">ya restado de lo que ganaste</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas anuladas</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($resumen['anuladas']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">no afectan estas cifras</p>
            </div>
        </div>

        {{-- La cifra que se compara con los arqueos: lo vendido mezcla tarjeta,
             QR y transferencia, que nunca pasan por un cajón. --}}
        <p class="text-theme-sm text-gray-600 dark:text-gray-400" data-efectivo-en-cajas>
            Efectivo que pasó por las cajas:
            <b class="text-gray-800 dark:text-white/90">{{ Config::importe($resumen['efectivo']) }}</b>
            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                — ventas y devoluciones en efectivo, más ingresos y menos egresos de caja. Cuadra con los arqueos
                de los turnos del período, sin su monto inicial.
            </span>
        </p>

        @if (Config::tasaImpuesto() > 0)
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                Impuesto del período: {{ Config::importe($resumen['impuesto']) }} (incluido en lo vendido).
            </p>
        @endif

        {{-- Evolución diaria --}}
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas por día</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Los días sin ventas se dibujan en cero: unir dos días lejanos con una recta aparentaría ventas que
                    no existieron.
                </p>
            </div>
            <div class="px-3 pb-3">
                <div data-apexchart="{{ json_encode($grafico) }}"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Método de pago --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por método de pago</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Solo el efectivo queda en el cajón; el resto no cuenta para el arqueo.
                    </p>
                </div>

                @if ($porMetodo->isEmpty())
                    <p class="px-6 pb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        Sin cobros en el período.
                    </p>
                @else
                    <div class="px-3">
                        <div data-apexchart="{{ json_encode($graficoMetodos) }}"></div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($porMetodo as $fila)
                                    <tr>
                                        <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->metodo_pago }}
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $fila->ventas }} venta(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ Config::importe($fila->monto) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Por cajero --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por cajero</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Quién vendió cuánto en el período.
                    </p>
                </div>

                <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cajero</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($porCajero as $fila)
                                <tr>
                                    <td class="px-6 py-3">
                                        <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->empleado }}
                                        </span>
                                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $fila->usuario }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $fila->ventas }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ Config::importe($fila->ticket) }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ Config::importe($fila->monto) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                        Sin ventas en el período.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Detalle diario --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle por día</h2>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Día</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket promedio</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Monto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_reverse(array_filter($porDia, fn ($d) => $d['ventas'] > 0)) as $dia)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                    <a href="{{ route('ventas.index', ['desde' => $dia['dia'], 'hasta' => $dia['dia']]) }}"
                                        class="hover:text-brand-500">
                                        {{ \Illuminate\Support\Carbon::parse($dia['dia'])->format('d/m/Y') }}
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $dia['ventas'] }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($dia['ticket']) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($dia['monto']) }}
                                </td>
                            </tr>
                        @endforeach

                        @if (collect($porDia)->every(fn ($d) => $d['ventas'] === 0))
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hubo ventas en el período.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
