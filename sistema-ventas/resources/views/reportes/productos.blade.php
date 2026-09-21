@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();
    $top = $masVendidos->take(10);

    $grafico = [
        'tipo' => 'bar',
        'moneda' => $moneda,
        'categorias' => $top->pluck('nombre')->map(fn ($n) => \Illuminate\Support\Str::limit($n, 18))->all(),
        'series' => [['name' => 'Vendido', 'data' => $top->pluck('monto_vendido')->map(fn ($m) => (float) $m)->all()]],
        'alto' => 340,
    ];
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.productos')" :excel="route('reportes.productos.excel')" :pdf="route('reportes.productos.pdf')" :desde="$desde" :hasta="$hasta" />

        <p class="text-theme-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Lo más vendido · del período elegido
        </p>

        @if ($masVendidos->isNotEmpty())
            <div class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-800 dark:bg-brand-500/10">
                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                    Tu estrella del menú:
                    <b class="text-brand-600 dark:text-brand-400">{{ $masVendidos->first()->nombre }}</b>,
                    con <b>{{ Config::importe($masVendidos->first()->monto_vendido) }}</b> vendidos
                </p>
            </div>
        @endif

        {{-- Más vendidos --}}
        @if ($masVendidos->isNotEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Más vendidos del período</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Por monto vendido, sin contar las ventas anuladas.{{ App\Support\Config::tasaImpuesto() > 0 ? ' Los importes van sin impuesto.' : '' }}
                    </p>
                </div>
                <div class="px-3 pb-3">
                    <div data-apexchart="{{ json_encode($grafico) }}"></div>
                </div>
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ranking del período</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Las cifras no cuentan las ventas anuladas. Lo vendido va neto del descuento
                    de cada venta, repartido entre sus líneas.
                    @if (App\Support\Config::tasaImpuesto() > 0)
                        <span data-rotulo-vendido>Y va sin impuesto: por eso no coincide con el «Vendido (con impuesto)» del reporte de ventas.</span>
                    @endif
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ítem del menú</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Unidades</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ \App\Http\Controllers\ReporteController::rotuloVendidoSinImpuesto() }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($masVendidos as $i => $fila)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $i + 1 }}</td>
                                <td class="px-6 py-3">
                                    <a href="{{ route('productos.show', $fila->id) }}"
                                        class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $fila->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $fila->codigo }}</span>
                                </td>
                                <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $fila->categoria }}</td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($fila->unidades_vendidas) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($fila->monto_vendido) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No se vendió nada en el período.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
