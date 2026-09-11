@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Cifras del recorte que se está mirando, no del total histórico:
             si se filtra por proveedor y mes, esto responde «cuánto le compré
             a este en septiembre», que es la pregunta que se hace. --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @php
                $tarjetas = [
                    ['Compras', number_format($resumen['compras']), 'text-gray-800 dark:text-white/90', 'en este recorte'],
                    ['Total comprado', Config::importe($resumen['total']), 'text-gray-800 dark:text-white/90', 'sin impuesto'],
                    ['Líneas', number_format($resumen['lineas']), 'text-gray-800 dark:text-white/90', 'productos cargados'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $etiqueta }}
                    </p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        {{-- Filtros --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('compras.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <x-form.campo label="Guía o factura" for="buscar" help="El número que trae el papel del proveedor.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="F001-00123" />
                </x-form.campo>

                <x-form.campo label="Proveedor" for="proveedor">
                    <x-form.select id="proveedor" name="proveedor" :value="$filtros['proveedor']"
                        placeholder="Todos" :opciones="$proveedores" />
                </x-form.campo>

                {{-- Dos campos sueltos y no `x-common.rango-fechas`: aquel trae
                     su propio `<form>` y exige un rango siempre puesto; aquí
                     las fechas son un filtro más y pueden ir vacías. --}}
                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']?->toDateString()" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']?->toDateString()" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('compras.index')">Limpiar</x-ui.button>
                    @puede('inventario.ingresar')
                        <x-ui.button size="sm" class="ml-auto" :href="route('compras.create')">Registrar compra</x-ui.button>
                    @endpuede
                </div>
            </form>
        </div>

        {{-- Listado --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="fecha" inicial="desc" defecto>Fecha</x-tabla.th>
                            <x-tabla.th clave="documento">Guía o factura</x-tabla.th>
                            <x-tabla.th clave="proveedor" class="hidden md:table-cell">Proveedor</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Líneas</x-tabla.th>
                            <x-tabla.th derecha>Total</x-tabla.th>
                            <x-tabla.th class="hidden lg:table-cell">Registró</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($compras as $compra)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $compra->fecha?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-4">
                                    <a href="{{ route('compras.show', $compra) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $compra->documento_externo ?: 'Compra #'.$compra->id }}
                                    </a>
                                    <span class="block text-theme-xs text-gray-500 md:hidden dark:text-gray-400">
                                        {{ $compra->proveedor?->razon_social }}
                                    </span>
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $compra->proveedor?->razon_social }}
                                </td>
                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ $compra->detalle_count }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($compra->total_documento) }}
                                </td>
                                <td class="hidden px-5 py-4 text-theme-xs text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ $compra->usuario?->usuario }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay compras con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$compras" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            Una compra registrada no se edita ni se borra: ya movió el stock y dejó su rastro en el kardex. Si algo
            se cargó mal, se corrige con un ajuste de inventario, que explica la diferencia y deja responsable.
        </p>
    </div>
@endsection
