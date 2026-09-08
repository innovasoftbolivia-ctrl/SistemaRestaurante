@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @php
                $tarjetas = [
                    ['Devoluciones', number_format($resumen['operaciones']), 'text-gray-800 dark:text-white/90', null],
                    ['Dinero devuelto', Config::importe($resumen['devuelto']), 'text-error-600 dark:text-error-400', 'salió del cajón'],
                    ['Totales', number_format($resumen['totales']), 'text-gray-800 dark:text-white/90', 'la venta completa'],
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

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Una devolución se registra desde la venta original. Revierte el stock que vuelve al estante y saca del
                cajón el dinero, al precio al que se vendió. Esta versión no emite nota de crédito.
            </p>

            <form method="GET" action="{{ route('devoluciones.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <x-form.campo label="Buscar" for="buscar" class="sm:col-span-2"
                    help="Comprobante, cliente o motivo.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="R001-000003" />
                </x-form.campo>

                <x-form.campo label="Tipo" for="tipo">
                    <x-form.select id="tipo" name="tipo" :value="$filtros['tipo']" placeholder="Todas"
                        :opciones="['TOTAL' => 'Totales', 'PARCIAL' => 'Parciales']" />
                </x-form.campo>

                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                </x-form.campo>

                <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('devoluciones.index')">Limpiar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" class="ml-auto" :href="route('ventas.index')">
                        Buscar la venta a devolver
                    </x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="fecha" defecto inicial="desc">Fecha</x-tabla.th>
                            {{-- «Venta» y «Cliente» no se ordenan: cuelgan de la
                                 venta y ninguna tiene un valor único por fila. --}}
                            <x-tabla.th>Venta</x-tabla.th>
                            <x-tabla.th>Cliente</x-tabla.th>
                            <x-tabla.th clave="motivo">Motivo</x-tabla.th>
                            <x-tabla.th clave="tipo">Tipo</x-tabla.th>
                            <x-tabla.th clave="registro">Registró</x-tabla.th>
                            <x-tabla.th clave="devuelto" inicial="desc" derecha>Devuelto</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($devoluciones as $devolucion)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    <a href="{{ route('devoluciones.show', $devolucion) }}" class="hover:text-brand-500">
                                        {{ $devolucion->fecha?->format('d/m/Y H:i') }}
                                    </a>
                                </td>
                                <td class="px-5 py-4">
                                    <a href="{{ route('ventas.show', $devolucion->venta_id) }}"
                                        class="font-mono text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $devolucion->venta?->comprobante?->numero_completo ?? '#'.$devolucion->venta_id }}
                                    </a>
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->venta?->cliente?->nombre ?? 'Cliente varios' }}
                                </td>
                                <td class="px-5 py-4">
                                    <span class="line-clamp-2 text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $devolucion->motivo }}
                                    </span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $devolucion->detalle_count }} línea(s)
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$devolucion->tipo === 'TOTAL' ? 'CESADO' : 'SUSPENDIDO'"
                                        :texto="$devolucion->tipo === 'TOTAL' ? 'Total' : 'Parcial'" />
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->usuario?->usuario }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-error-600 dark:text-error-400">
                                    − {{ Config::importe($devolucion->total) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
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
