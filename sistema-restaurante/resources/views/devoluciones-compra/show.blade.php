@extends('layouts.app')

@php
    use App\Support\Config;

    $tonoEspera = [
        'REPUESTO' => 'ACTIVO',
        'PENDIENTE' => 'SUSPENDIDO',
        'NOTA_CREDITO' => 'PLAZO_FIJO',
    ];

    $pendiente = $devolucion->espera === 'PENDIENTE';
    $debe = $devolucion->debe_reponer;
    $totalPorReponer = round((float) $devolucion->detalle->sum(fn ($d) => $d->por_reponer), 3);
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="mb-2 flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            Devolución #{{ $devolucion->id }}
                        </h2>
                        <x-ui.estado :estado="$tonoEspera[$devolucion->espera] ?? null"
                            :texto="$esperas[$devolucion->espera] ?? $devolucion->espera" />
                        @if ($pendiente)
                            <x-ui.estado :estado="$debe ? 'CESADO' : 'ACTIVO'"
                                :texto="$debe ? 'El proveedor debe '.Config::cantidad($totalPorReponer).' u.' : 'Repuesta completa'" />
                        @endif
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $devolucion->fecha?->format('d/m/Y H:i') }} ·
                        registró {{ $devolucion->usuario?->usuario }}
                    </p>
                </div>

                <div class="text-left lg:text-right">
                    <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Total devuelto</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ Config::importe($devolucion->total) }}</p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">a costo de la compra</p>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-gray-100 pt-5 text-theme-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-800">
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Proveedor</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $devolucion->compra?->proveedor?->razon_social }}</dd>
                </div>
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Compra</dt>
                    <dd>
                        <a href="{{ route('compras.show', $devolucion->compra_id) }}"
                            class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                            #{{ $devolucion->compra_id }}
                            @if ($devolucion->compra?->documento_externo)
                                · {{ $devolucion->compra->documento_externo }}
                            @endif
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Motivo</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $motivos[$devolucion->motivo] ?? $devolucion->motivo }}</dd>
                </div>
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Documento del proveedor</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $devolucion->documento_externo ?: '—' }}</dd>
                </div>
                @if ($devolucion->observacion)
                    <div class="sm:col-span-2 lg:col-span-4">
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Observación</dt>
                        <dd class="text-gray-800 dark:text-white/90">{{ $devolucion->observacion }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Líneas --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th derecha>Cantidad</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Costo</x-tabla.th>
                            <x-tabla.th derecha>Importe</x-tabla.th>
                            <x-tabla.th derecha class="hidden md:table-cell">Repuesto</x-tabla.th>
                            <x-tabla.th derecha class="hidden md:table-cell">Por reponer</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($devolucion->detalle as $linea)
                            <tr>
                                <td class="px-5 py-4 text-theme-sm">
                                    <span class="block font-medium text-gray-800 dark:text-white/90">{{ $linea->producto?->nombre }}</span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $linea->producto?->codigo }}</span>
                                </td>
                                <td class="px-5 py-4 text-right text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($linea->cantidad) }}
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm whitespace-nowrap text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ Config::importe($linea->costo_unitario) }}
                                </td>
                                <td class="px-5 py-4 text-right text-theme-sm whitespace-nowrap font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($linea->importe) }}
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $devolucion->espera === 'NOTA_CREDITO' ? '—' : Config::cantidad($linea->cantidad_repuesta) }}
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm md:table-cell">
                                    @if (! $pendiente)
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @elseif ($linea->por_reponer > 0)
                                        <span class="font-medium text-warning-700 dark:text-orange-400">{{ Config::cantidad($linea->por_reponer) }}</span>
                                    @else
                                        <span class="text-success-700 dark:text-success-500">0</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Lo que el proveedor trajo después --}}
        @if ($pendiente && $debe)
            <form method="POST" action="{{ route('devoluciones-compra.reponer', $devolucion) }}">
                @csrf
                @unEnvio

                <x-common.component-card title="Registrar lo que llegó"
                    desc="Cuando el proveedor traiga el reemplazo, anota cuánto trajo de cada producto: entra al stock en ese momento. Puede traerlo en partes.">
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($devolucion->detalle as $linea)
                            @continue($linea->por_reponer <= 0)
                            <div class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                <label for="repuesto-{{ $linea->id }}" class="text-theme-sm">
                                    <span class="block font-medium text-gray-800 dark:text-white/90">{{ $linea->producto?->nombre }}</span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        debe {{ Config::cantidad($linea->por_reponer) }} de {{ Config::cantidad($linea->cantidad) }}
                                    </span>
                                </label>
                                <x-form.input type="number" id="repuesto-{{ $linea->id }}"
                                    name="cantidades[{{ $linea->id }}]" :value="old('cantidades.'.$linea->id)"
                                    min="0" max="{{ $linea->por_reponer }}" step="any" placeholder="0"
                                    class="max-w-32 text-right" />
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="success">Registrar lo que llegó</x-ui.button>
                    </div>
                </x-common.component-card>
            </form>
        @endif

        <div class="flex flex-wrap gap-3">
            <x-ui.button variant="outline" :href="route('devoluciones-compra.index')">Volver a las devoluciones</x-ui.button>
        </div>
    </div>
@endsection
