@extends('layouts.app')

@php
    use App\Support\Config;

    // Tono de la etiqueta de cada final, con los estilos de x-ui.estado.
    $tonoEspera = [
        'REPUESTO' => 'ACTIVO',
        'PENDIENTE' => 'SUSPENDIDO',
        'NOTA_CREDITO' => 'PLAZO_FIJO',
    ];
@endphp

@section('content')
    <div class="space-y-6">

        @if ($debiendo > 0)
            <x-ui.alert variant="warning" title="El proveedor debe mercadería"
                :message="$debiendo === 1
                    ? 'Hay 1 devolución esperando el reemplazo. Cuando llegue, regístralo desde la devolución.'
                    : 'Hay '.$debiendo.' devoluciones esperando el reemplazo. Cuando llegue, regístralo desde cada devolución.'" />
        @endif

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('devoluciones-compra.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <x-form.campo label="En qué quedó" for="espera">
                    <x-form.select id="espera" name="espera" :value="$filtros['espera']" placeholder="Todas"
                        :opciones="$esperas" />
                </x-form.campo>

                <x-form.campo label="Proveedor" for="proveedor">
                    <x-form.select id="proveedor" name="proveedor" :value="$filtros['proveedor']" placeholder="Todos"
                        :opciones="$proveedores" />
                </x-form.campo>

                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                </x-form.campo>

                <div class="flex items-center lg:pt-9">
                    <x-form.check name="debe" :checked="$filtros['debe']" label="Solo lo que el proveedor debe" />
                </div>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('devoluciones-compra.index')">Limpiar</x-ui.button>
                    <p class="ml-auto self-center text-theme-xs text-gray-500 dark:text-gray-400">
                        Para devolver algo, abre la compra en que llegó y elige «Devolver al proveedor».
                    </p>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Devolución</x-tabla.th>
                            <x-tabla.th class="hidden sm:table-cell">Compra / proveedor</x-tabla.th>
                            <x-tabla.th class="hidden md:table-cell">Motivo</x-tabla.th>
                            <x-tabla.th>En qué quedó</x-tabla.th>
                            <x-tabla.th class="hidden lg:table-cell">Reposición</x-tabla.th>
                            <x-tabla.th derecha>Total</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($devoluciones as $devolucion)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <a href="{{ route('devoluciones-compra.show', $devolucion) }}"
                                        class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        Devolución #{{ $devolucion->id }}
                                    </a>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $devolucion->fecha?->format('d/m/Y H:i') }}
                                    </span>
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    <span class="block text-gray-800 dark:text-white/90">
                                        {{ $devolucion->compra?->proveedor?->razon_social }}
                                    </span>
                                    <span class="text-theme-xs">
                                        Compra #{{ $devolucion->compra_id }}
                                        @if ($devolucion->compra?->documento_externo)
                                            · {{ $devolucion->compra->documento_externo }}
                                        @endif
                                    </span>
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ \App\Models\DevolucionCompra::MOTIVOS[$devolucion->motivo] ?? $devolucion->motivo }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$tonoEspera[$devolucion->espera] ?? null"
                                        :texto="$esperas[$devolucion->espera] ?? $devolucion->espera" />
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm lg:table-cell">
                                    @if ($devolucion->espera !== 'PENDIENTE')
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @elseif ($devolucion->debe_reponer)
                                        <span class="font-medium text-warning-700 dark:text-orange-400">
                                            Debe {{ Config::cantidad($devolucion->detalle->sum(fn ($d) => $d->por_reponer)) }} u.
                                        </span>
                                    @else
                                        <span class="text-success-700 dark:text-success-500">Repuesta</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap font-medium text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::importe($devolucion->total) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay devoluciones con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$devoluciones" />
        </div>
    </div>
@endsection
