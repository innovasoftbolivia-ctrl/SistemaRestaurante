@extends('layouts.app')

@php
    use App\Support\Config;

    // Sin impuesto, «Venta» y «Estante» son el mismo importe: se muestra una
    // sola columna, y es la que tiene que sobrevivir en el teléfono.
    // Con el IVA incluido en el precio también es un solo importe.
    $tasa = Config::preciosIncluyenImpuesto() ? 0 : Config::tasaImpuesto();
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Resumen del menú --}}
        <div class="grid grid-cols-1 gap-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Ítems en el menú
                </p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                    {{ number_format($resumen['total']) }}
                </p>
            </div>
        </div>

        {{-- Filtros --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('productos.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar" help="Nombre o código interno.">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Milanesa o P-0004" autofocus />
                    </x-form.campo>
                </div>

                <x-form.campo label="Categoría" for="categoria">
                    <x-form.select id="categoria" name="categoria" :value="$filtros['categoria']"
                        placeholder="Todas" :opciones="$categorias" />
                </x-form.campo>

                {{-- Arranca en «En el menú»: es lo que se quiere ver casi siempre.
                     Lo retirado no se borra —tiene historia detrás— y por eso hay
                     que poder llegar a ello, pero no tropezarse. --}}
                <x-form.campo label="Estado" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Todos"
                        :opciones="['ACTIVO' => 'En el menú', 'CESADO' => 'Fuera del menú']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('productos.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" :href="route('productos.create')">Agregar al menú</x-ui.button>
                </div>

            </form>
        </div>

        {{-- Tabla --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="nombre" defecto>Nombre</x-tabla.th>
                            {{-- En el teléfono quedan nombre, estante y acciones:
                                 lo que hace falta para reconocerlo. --}}
                            <x-tabla.th clave="categoria" class="hidden md:table-cell">Categoría</x-tabla.th>
                            <x-tabla.th clave="venta" inicial="desc" derecha
                                @class(['hidden lg:table-cell' => $tasa > 0])>
                                {{ $tasa > 0 ? 'Venta (base)' : 'Venta' }}
                            </x-tabla.th>
                            @if ($tasa > 0)
                                <x-tabla.th clave="estante" inicial="desc" derecha>Estante</x-tabla.th>
                            @endif
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($productos as $producto)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.foto-producto :producto="$producto" size="sm" />
                                        <div class="min-w-0">
                                            <a href="{{ route('productos.show', $producto) }}"
                                                class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                                {{ $producto->nombre }}
                                            </a>
                                            <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $producto->codigo }}
                                            </span>
                                            @unless ($producto->activo)
                                                <x-ui.estado estado="CESADO" texto="Fuera del menú" class="ml-1" />
                                            @endunless
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $producto->categoria?->nombre }}
                                </td>
                                <td
                                    class="@if ($tasa > 0) hidden text-gray-500 lg:table-cell dark:text-gray-400 @else font-medium text-gray-800 dark:text-white/90 @endif px-5 py-4 text-right whitespace-nowrap text-theme-sm">
                                    {{ Config::importe($producto->precio_venta) }}
                                </td>
                                @if ($tasa > 0)
                                    <td class="px-5 py-4 text-right whitespace-nowrap">
                                        <span class="font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                            {{ Config::importe($producto->precio_estante) }}
                                        </span>
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $producto->afecto_impuesto ? 'con impuesto' : 'exonerado' }}
                                        </span>
                                    </td>
                                @endif
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('productos.show', $producto) }}" title="Ver ficha"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z"
                                                    stroke="currentColor" stroke-width="1.5" />
                                                <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.5" />
                                            </svg>
                                        </a>
                                        <a href="{{ route('productos.edit', $producto) }}" title="Editar"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 20h4L19 9a2.8 2.8 0 1 0-4-4L4 16v4Z" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-0">
                                    <x-common.vacio icono="busqueda" titulo="No se encontró nada en el menú con esos criterios." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$productos" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            @if (Config::tasaImpuesto() > 0 && Config::preciosIncluyenImpuesto())
                El precio de venta es <b>el precio final con el IVA incluido</b>: es lo que paga el cliente.
            @elseif (Config::tasaImpuesto() > 0)
                El precio de venta se registra <b>sin impuesto</b>. La columna «Estante» es lo que
                paga el cliente: precio base más el {{ number_format(Config::tasaImpuesto() * 100, 0) }}% de impuesto.
            @else
                El precio de venta es <b>el precio final</b>: es lo que paga el cliente, tal cual.
            @endif
        </p>
    </div>
@endsection
