@extends('layouts.app')

@php
    use App\Support\Config;

    $unidad = $producto->unidadMedida?->codigo;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $producto->nombre }}</h2>
                    <p class="font-mono text-theme-sm text-gray-500 dark:text-gray-400">{{ $producto->codigo }}</p>
                    <p class="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                        Stock total: <b class="text-gray-800 dark:text-white/90">{{ Config::cantidad($producto->stock_actual) }} {{ $unidad }}</b>
                        @if ($sinFecha > 0)
                            · {{ Config::cantidad($sinFecha) }} sin fecha registrada
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="outline" :href="route('productos.show', $producto)">Ver ficha</x-ui.button>
                    <x-ui.button size="sm" variant="outline" :href="route('vencimientos.index')">Volver</x-ui.button>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Tandas</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    En el orden en que salen: primero lo que vence antes. Las tandas sin fecha van al final, porque
                    no se sabe cuándo vencen y no pueden reclamar prioridad.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Vence', 'Lote', 'Entró', 'Vendido', 'Queda', 'Vino de'] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ in_array($i, [2, 3, 4], true) ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($lotes as $lote)
                            @php($agotado = (float) $lote->cantidad_actual <= 0)
                            <tr class="{{ $agotado ? 'opacity-50' : '' }}">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm">
                                    @if ($lote->fecha_vencimiento)
                                        <span class="{{ $lote->vencido ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                                            {{ $lote->fecha_vencimiento->format('d/m/Y') }}
                                        </span>
                                        @unless ($agotado)
                                            <span class="block text-theme-xs {{ $lote->vencido ? 'text-error-600 dark:text-error-400' : 'text-gray-500 dark:text-gray-400' }}">
                                                {{ $lote->vencido ? 'vencido' : 'faltan '.$lote->dias_para_vencer.' d' }}
                                            </span>
                                        @endunless
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">sin fecha</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $lote->codigo ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($lote->cantidad_inicial) }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($lote->consumido) }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($lote->cantidad_actual) }} {{ $unidad }}
                                </td>
                                <td class="px-5 py-4 text-theme-xs text-gray-500 dark:text-gray-400">
                                    @if ($lote->compraDetalle?->compra)
                                        <a href="{{ route('compras.show', $lote->compraDetalle->compra_id) }}"
                                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            {{ $lote->compraDetalle->compra->documento_externo ?: 'Compra #'.$lote->compraDetalle->compra_id }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Este producto todavía no tiene tandas registradas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$lotes" />
        </div>
    </div>
@endsection
