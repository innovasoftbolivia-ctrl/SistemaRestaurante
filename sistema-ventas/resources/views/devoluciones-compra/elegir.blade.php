@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        <x-common.component-card title="¿De qué factura se devuelve?"
            desc="Busca el papel con el que llegó esa mercadería. Solo salen las compras a las que todavía les queda algo por devolver.">
            <form method="GET" action="{{ route('devoluciones-compra.elegir') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <x-form.campo label="Guía o factura" for="buscar"
                    help="El número que trae el papel del proveedor.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                        placeholder="F001-01268" autofocus />
                </x-form.campo>

                <x-form.campo label="Proveedor" for="proveedor">
                    <x-form.select id="proveedor" name="proveedor" :value="$filtros['proveedor']"
                        placeholder="Todos" :opciones="$proveedores" />
                </x-form.campo>

                <div class="flex flex-wrap items-end gap-2 pb-1">
                    <x-ui.button type="submit" size="sm">Buscar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('devoluciones-compra.elegir')">Limpiar</x-ui.button>
                </div>
            </form>
        </x-common.component-card>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Fecha', 'Guía o factura', 'Proveedor', 'Líneas', 'Total', 'Sin devolver', ''] as $i => $columna)
                                @php($ancho = $i === 5 ? 'whitespace-nowrap' : '')
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $ancho }} {{ $i >= 3 ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($compras as $compra)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $compra->fecha?->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-4">
                                    <a href="{{ route('compras.show', $compra) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $compra->documento_externo ?: 'Compra #'.$compra->id }}
                                    </a>
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $compra->proveedor?->razon_social }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $compra->detalle_count }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($compra->total_documento) }}
                                </td>
                                {{-- Líneas y no unidades: una factura mezcla
                                     unidades, kilos y litros, y sumarlos daría
                                     un número que no quiere decir nada. --}}
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $compra->lineas_pendientes }}
                                    {{ (int) $compra->lineas_pendientes === 1 ? 'línea' : 'líneas' }}
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <x-ui.button size="sm" :href="route('devoluciones-compra.create', $compra)">
                                        Devolver
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    @if ($filtros['buscar'] !== '' || $filtros['proveedor'])
                                        Ninguna compra coincide con esa búsqueda.
                                    @else
                                        No hay compras con algo pendiente de devolver.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$compras" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            También se llega desde la propia factura: Almacén → Compras, abrirla y usar «Devolver al proveedor».
            Es el mismo formulario; solo cambia por dónde se entra.
        </p>
    </div>
@endsection
