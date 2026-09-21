@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();
    $top = $masVendidos->take(10);
    $rotulo = \App\Http\Controllers\ReporteController::rotuloVendidoSinImpuesto();

    $grafico = [
        'tipo' => 'bar',
        'moneda' => $moneda,
        'categorias' => $top->pluck('nombre')->map(fn ($n) => \Illuminate\Support\Str::limit($n, 24))->all(),
        'horizontal' => true,
        'series' => [['name' => 'Vendido', 'data' => $top->pluck('monto_vendido')->map(fn ($m) => (float) $m)->all()]],
        'alto' => 340,
    ];

    // Cuánto del total se llevan los cinco primeros: dice si el negocio
    // depende de pocos platos.
    $pesoTop5 = $totalVendido > 0 ? $masVendidos->take(5)->sum('monto_vendido') / $totalVendido * 100 : 0;
    $masPedido = $masVendidos->sortByDesc('unidades_vendidas')->first();
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.productos')" :excel="route('reportes.productos.excel')" :pdf="route('reportes.productos.pdf')" :desde="$desde" :hasta="$hasta" />

        @if ($masVendidos->isNotEmpty())
            {{-- Lo que responde «¿qué es lo que más me deja?» antes que la tabla. --}}
            <div class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-800 dark:bg-brand-500/10" data-resumen-menu>
                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                    Lo que más te deja:
                    <b class="text-brand-600 dark:text-brand-400">{{ $masVendidos->first()->nombre }}</b>,
                    con <b>{{ Config::importe($masVendidos->first()->monto_vendido) }}</b>.
                    @if ($masPedido && $masPedido->id !== $masVendidos->first()->id)
                        El más pedido es <b>{{ $masPedido->nombre }}</b> ({{ Config::cantidad($masPedido->unidades_vendidas) }} unidades).
                    @endif
                </p>
                @if ($masVendidos->count() > 5)
                    <p class="mt-1 text-theme-sm text-gray-700 dark:text-gray-300">
                        Los cinco primeros suman el <b>{{ number_format($pesoTop5, 0) }}%</b> de lo vendido.
                    </p>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Más vendidos --}}
            <div class="rounded-2xl border border-gray-200 bg-white xl:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Los diez que más venden</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Por monto vendido, sin contar las ventas anuladas.{{ Config::tasaImpuesto() > 0 ? ' Los importes van sin impuesto.' : '' }}
                    </p>
                </div>
                @if ($masVendidos->isNotEmpty())
                    <div class="px-3 pb-3">
                        <div data-apexchart="{{ json_encode($grafico) }}"></div>
                    </div>
                @else
                    <p class="px-6 pb-6 text-theme-sm text-gray-500 dark:text-gray-400">No se vendió nada en el período.</p>
                @endif
            </div>

            {{-- Por categoría --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-por-categoria>
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por categoría</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Cuánto aporta cada sección de la carta.</p>
                </div>
                <div class="border-t border-gray-100 dark:border-gray-800">
                    @forelse ($porCategoria as $c)
                        @php $peso = $totalVendido > 0 ? (float) $c->monto / $totalVendido * 100 : 0; @endphp
                        <div class="px-6 py-3">
                            <div class="flex items-baseline justify-between text-theme-sm">
                                <span class="text-gray-800 dark:text-white/90">{{ $c->categoria }}</span>
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ Config::importe($c->monto) }}</span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                                    <div class="h-1.5 rounded-full bg-brand-500" style="width: {{ max(1, round($peso)) }}%"></div>
                                </div>
                                <span class="w-10 text-right text-theme-xs text-gray-500 dark:text-gray-400">{{ number_format($peso, 0) }}%</span>
                            </div>
                        </div>
                    @empty
                        <p class="px-6 py-6 text-theme-sm text-gray-500 dark:text-gray-400">Sin ventas en el período.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Ranking completo --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Todo lo vendido</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Las cifras no cuentan las ventas anuladas. Lo vendido va neto del descuento
                    de cada venta, repartido entre sus líneas.
                    @if (Config::tasaImpuesto() > 0)
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
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $rotulo }}</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">% del total</th>
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
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $totalVendido > 0 ? number_format((float) $fila->monto_vendido / $totalVendido * 100, 1) : '0.0' }}%
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

        {{-- Lo que nadie pidió: el primer candidato a revisar o a sacar. --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-sin-ventas>
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Sin ninguna venta</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($sinVentas->isEmpty())
                        Todo lo que está en la carta se vendió al menos una vez en el período.
                    @else
                        {{ $sinVentas->count() }} {{ $sinVentas->count() === 1 ? 'ítem está' : 'ítems están' }} en la carta y nadie
                        {{ $sinVentas->count() === 1 ? 'lo pidió' : 'los pidió' }} en el período. Conviene revisar su precio, su foto o
                        si vale la pena seguir ofreciéndolos.
                    @endif
                </p>
            </div>
            @if ($sinVentas->isNotEmpty())
                <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($sinVentas as $p)
                        <li class="flex items-baseline justify-between gap-3 px-6 py-3">
                            <a href="{{ route('productos.show', $p->id) }}" class="text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                {{ $p->nombre }}
                                <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $p->codigo }}</span>
                            </a>
                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $p->categoria }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
