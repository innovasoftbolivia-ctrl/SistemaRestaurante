@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Por motivo y no un total suelto: «Bs 900 por vencimiento» es una
             conversación con el proveedor distinta de «Bs 900 porque vino
             fallado», y sumarlas las haría desaparecer a las dos. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($motivos as $clave => $etiqueta)
                @php($fila = $porMotivo[$clave] ?? null)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $etiqueta }}
                    </p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ Config::importe($fila->total ?? 0) }}
                    </p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $fila->documentos ?? 0 }}
                        {{ ($fila->documentos ?? 0) === 1 ? 'devolución' : 'devoluciones' }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('devoluciones-compra.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <x-form.campo label="Motivo" for="motivo">
                    <x-form.select id="motivo" name="motivo" :value="$filtros['motivo']"
                        placeholder="Todos" :opciones="$motivos" />
                </x-form.campo>

                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']?->toDateString()" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']?->toDateString()" />
                </x-form.campo>

                <div class="flex flex-wrap items-end gap-2 pb-1">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('devoluciones-compra.index')">Limpiar</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Fecha', 'Documento', 'Proveedor', 'Motivo', 'Líneas', 'Total'] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $i >= 4 ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($devoluciones as $devolucion)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->fecha?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-4">
                                    <a href="{{ route('devoluciones-compra.show', $devolucion) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $devolucion->documento_externo ?: 'Devolución #'.$devolucion->id }}
                                    </a>
                                    @if ($devolucion->con_reposicion)
                                        <span class="block text-theme-xs text-success-700 dark:text-success-500">con reposición</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->compra?->proveedor?->razon_social }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->etiqueta_motivo }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->detalle_count }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
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

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            Una devolución se registra desde la compra por la que entró la mercadería: entra a Almacén → Compras,
            abre la factura y usa «Devolver al proveedor». Así nunca se devuelve contra el proveedor equivocado.
        </p>
    </div>
@endsection
