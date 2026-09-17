@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        @facturacion
        <x-ui.en-construccion titulo="Régimen tributario en construcción">
            @if (Config::tasaImpuesto() > 0)
                La tasa de impuesto ({{ number_format(Config::tasaImpuesto() * 100, 0) }}%) y la identificación fiscal
            @else
                El régimen sin impuesto desglosado y la identificación fiscal
            @endif
            del negocio son provisionales, y los documentos salen impresos con ese aviso. La numeración, el desglose y la
            trazabilidad sí están terminados: lo que falta es fijar los datos tributarios y, más adelante, la
            facturación electrónica.
        </x-ui.en-construccion>
        @endfacturacion

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Cada documento guarda una foto de los datos del cliente y de los importes al momento de emitirlo.
                Los correlativos no se reutilizan: un documento anulado conserva su número.
            </p>

            <form method="GET" action="{{ route('comprobantes.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar" help="Número, cliente o documento.">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="F001-000012" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Serie" for="serie">
                    <x-form.select id="serie" name="serie" :value="$filtros['serie']" placeholder="Todas"
                        :opciones="$series" />
                </x-form.campo>

                <x-form.campo label="Estado" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Todos"
                        :opciones="$estados" />
                </x-form.campo>

                <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('comprobantes.index')">Limpiar</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="numero">Número</x-tabla.th>
                            <x-tabla.th clave="tipo">Tipo</x-tabla.th>
                            <x-tabla.th clave="fecha" defecto inicial="desc">Fecha</x-tabla.th>
                            <x-tabla.th clave="cliente">Cliente</x-tabla.th>
                            <x-tabla.th clave="estado">Estado</x-tabla.th>
                            <x-tabla.th clave="total" inicial="desc" derecha>Total</x-tabla.th>
                            <x-tabla.th derecha><span class="sr-only">Acciones</span></x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($comprobantes as $doc)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 font-mono font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $doc->numero_completo }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $doc->serie?->tipo?->nombre }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $doc->fecha_emision?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-4">
                                    <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                        {{ $doc->cliente_nombre }}
                                    </span>
                                    @if ($doc->cliente_documento)
                                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $doc->cliente_tipo_documento }} {{ $doc->cliente_documento }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado
                                        :estado="match ($doc->estado) { 'EMITIDO' => 'ACTIVO', 'ANULADO' => 'CESADO', default => 'SUSPENDIDO' }"
                                        :texto="ucfirst(mb_strtolower($doc->estado))" />
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium {{ $doc->estado === 'ANULADO' ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-white/90' }}">
                                    {{ Config::importe($doc->total) }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('comprobantes.imprimir', $doc) }}" target="_blank"
                                            title="Imprimir"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                                <rect x="7" y="14" width="10" height="6" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </a>
                                        <a href="{{ route('ventas.show', $doc->venta_id) }}" title="Ver venta"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z"
                                                    stroke="currentColor" stroke-width="1.5" />
                                                <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.5" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay comprobantes con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$comprobantes" />
        </div>
    </div>
@endsection
