@extends('layouts.app')

@php
    use App\Support\Config;

    $stock = (float) $producto->stock_actual;
@endphp

@section('content')
    <div class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $producto->codigo }}</p>
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $producto->nombre }}</h2>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $producto->categoria?->nombre ?? 'Sin categoría' }}
                        · {{ $producto->empaque_visible ?? 'por unidad' }}
                        @if ($producto->costo !== null)
                            · último costo {{ Config::importe($producto->costo) }}
                        @endif
                        @unless ($producto->controla_stock)
                            · <b>ya no lleva inventario</b>
                        @endunless
                    </p>
                </div>
                <div class="sm:text-right">
                    <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock actual</p>
                    <p class="text-title-sm font-semibold tabular-nums {{ $stock < 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                        {{ Config::cantidad($stock) }} u.
                    </p>
                    @if ($producto->empaque_visible && $stock > 0)
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $producto->stock_en_empaques }}</p>
                    @elseif ($stock < 0)
                        <p class="text-theme-xs text-error-600 dark:text-error-400">Se vendió sin stock: revisar</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('inventario.kardex', $producto) }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('inventario.kardex', $producto)">Limpiar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" class="ml-auto" :href="route('inventario.index')">Volver al stock</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Fecha</x-tabla.th>
                            <x-tabla.th>Movimiento</x-tabla.th>
                            <x-tabla.th derecha>Cantidad</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Stock</x-tabla.th>
                            <x-tabla.th derecha class="hidden lg:table-cell">Costo</x-tabla.th>
                            <x-tabla.th class="hidden md:table-cell">Usuario</x-tabla.th>
                            <x-tabla.th class="hidden lg:table-cell">Motivo</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($movimientos as $movimiento)
                            @php
                                $cantidad = $movimiento->cantidad_con_signo;

                                // El documento que originó el movimiento, si lo hay.
                                [$enlace, $documento] = match (true) {
                                    (bool) $movimiento->venta_id => [route('ventas.show', $movimiento->venta_id), 'Venta #'.$movimiento->venta_id],
                                    (bool) $movimiento->compra_id => [route('compras.show', $movimiento->compra_id), 'Compra #'.$movimiento->compra_id],
                                    (bool) $movimiento->devolucion_compra_id => [route('devoluciones-compra.show', $movimiento->devolucion_compra_id), 'Devolución #'.$movimiento->devolucion_compra_id],
                                    (bool) $movimiento->toma_id => [route('tomas.show', $movimiento->toma_id), 'Toma #'.$movimiento->toma_id],
                                    default => [null, null],
                                };
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $movimiento->fecha?->format('d/m/Y') }}
                                    <span class="block text-theme-xs">{{ $movimiento->fecha?->format('H:i') }}</span>
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ $movimiento->origen_visible }}
                                    @if ($enlace)
                                        <a href="{{ $enlace }}"
                                            class="block text-theme-xs text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $documento }}</a>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm">
                                    <span class="font-medium tabular-nums {{ $cantidad >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                        {{ $cantidad > 0 ? '+' : '−' }}{{ Config::cantidad(abs($cantidad)) }}
                                    </span>
                                    <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->tipo === 'ENTRADA' ? 'entrada' : 'salida' }}
                                    </span>
                                </td>
                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ Config::cantidad($movimiento->stock_anterior) }} →
                                    <b class="{{ (float) $movimiento->stock_resultante < 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">{{ Config::cantidad($movimiento->stock_resultante) }}</b>
                                </td>
                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ $movimiento->costo_unitario !== null ? Config::importe($movimiento->costo_unitario) : '—' }}
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $movimiento->usuario?->usuario ?? '—' }}
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ $movimiento->motivo ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay movimientos en esas fechas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$movimientos" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            El kardex no se edita ni se borra: si un movimiento salió mal, la corrección es otro movimiento encima. Con la
            columna «Stock» (antes → después) se puede rehacer la cuenta y comprobar que no falta nada.
        </p>
    </div>
@endsection
