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

        {{-- Lo primero que un dueño de negocio se pregunta al abrir esto:
             "¿qué me falta comprar?". Antes que cualquier cifra de inventario. --}}
        @if ($alertas->isNotEmpty())
            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-6 dark:border-orange-900 dark:bg-orange-500/15">
                <p class="flex items-center gap-2 text-title-sm font-bold text-warning-700 dark:text-orange-400">
                    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Tienes {{ $alertas->count() }} {{ $alertas->count() === 1 ? 'producto' : 'productos' }} por reponer
                </p>
                <p class="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    Se están por acabar o ya se acabaron. Están en la lista de abajo.
                </p>
            </div>
        @else
            <div class="rounded-2xl border border-success-200 bg-success-50 p-6 dark:border-success-900 dark:bg-success-500/15">
                <p class="flex items-center gap-2 text-title-sm font-bold text-success-700 dark:text-success-500">
                    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Todo tu inventario está en orden
                </p>
                <p class="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    Ningún producto está por debajo de su stock mínimo hoy.
                </p>
            </div>
        @endif

        {{-- Inventario, que es una foto de hoy y no depende del rango --}}
        <p class="text-theme-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Estado del inventario · hoy
        </p>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
            @php
                $tarjetas = [
                    ['Productos en catálogo', number_format($inventario['productos']), 'text-gray-800 dark:text-white/90', null],
                    ['Tienes invertido en estante', Config::importe($inventario['costo']), 'text-gray-800 dark:text-white/90', null],
                    ['Si vendieras todo esto, ganarías', Config::importe($inventario['margen']), 'text-success-700 dark:text-success-500', null],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Alertas de stock --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                <div>
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle de lo que reponer</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Productos que llegaron a su stock mínimo (objetivo O7).
                    </p>
                </div>
                @if ($alertas->isNotEmpty())
                    <x-ui.estado estado="SUSPENDIDO" :texto="$alertas->count().' producto(s)'" />
                @endif
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Stock</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mínimo</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Faltante</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($alertas as $alerta)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3">
                                    <a href="{{ route('productos.show', $alerta->id) }}"
                                        class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $alerta->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $alerta->codigo }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $alerta->categoria }}</td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium {{ (float) $alerta->stock_actual <= 0 ? 'text-error-600 dark:text-error-400' : 'text-warning-700 dark:text-orange-400' }}">
                                    {{ Config::cantidad($alerta->stock_actual) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($alerta->stock_minimo) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($alerta->faltante) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-theme-sm text-success-700 dark:text-success-500">
                                    Ningún producto está por debajo de su stock mínimo.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-theme-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Lo más vendido · del período elegido
        </p>

        @if ($masVendidos->isNotEmpty())
            <div class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-800 dark:bg-brand-500/10">
                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                    Tu producto estrella:
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
                        Por monto vendido neto de devoluciones. Los importes van sin impuesto.
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
                    Todas las cifras van <b>netas de devoluciones</b>: lo que el negocio se quedó. El margen es
                    estimado, porque compara lo vendido contra el precio de compra <b>actual</b> del producto, que
                    puede no ser el que tenía al venderse.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Unidades</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vendido</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Margen</th>
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
                                    @if ((float) $fila->unidades_devueltas > 0)
                                        <span class="block text-theme-xs text-error-600 dark:text-error-400">
                                            {{ Config::cantidad($fila->unidades_devueltas) }} devuelta(s)
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($fila->monto_vendido) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium {{ (float) $fila->margen_estimado >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                    {{ Config::importe($fila->margen_estimado) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
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
