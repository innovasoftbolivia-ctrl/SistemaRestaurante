@extends('layouts.app')

@php
    use App\Support\Config;

    // Sin impuesto, «Venta» y «Estante» son el mismo importe: se muestra una
    // sola columna, y es la que tiene que sobrevivir en el teléfono.
    $tasa = Config::tasaImpuesto();
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Resumen del catálogo --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @php
                // Las clases van completas y literales: Tailwind rastrea el texto de
                // la plantilla, así que un `text-{{ $color }}-500` nunca se generaría.
                $tarjetas = [
                    ['Productos activos', number_format($resumen['total']), 'text-gray-800 dark:text-white/90', null],
                    ['Valor del inventario', Config::importe($resumen['valor']), 'text-success-700 dark:text-success-500', 'al precio de compra'],
                    ['Bajo el mínimo', number_format($resumen['bajo_minimo']), 'text-warning-700 dark:text-orange-400', 'conviene reponer'],
                    ['Agotados', number_format($resumen['agotados']), 'text-error-600 dark:text-error-400', 'sin stock'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $etiqueta }}
                    </p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Filtros --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('productos.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar" help="Nombre, código interno o código de barras.">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Arroz, P-0001 o 7750001000011" autofocus />
                    </x-form.campo>
                </div>

                <x-form.campo label="Categoría" for="categoria">
                    <x-form.select id="categoria" name="categoria" :value="$filtros['categoria']"
                        placeholder="Todas" :opciones="$categorias" />
                </x-form.campo>

                <x-form.campo label="Proveedor" for="proveedor">
                    <x-form.select id="proveedor" name="proveedor" :value="$filtros['proveedor']"
                        placeholder="Todos" :opciones="$proveedores" />
                </x-form.campo>

                <x-form.campo label="Stock" for="stock">
                    <x-form.select id="stock" name="stock" :value="$filtros['stock']" placeholder="Cualquiera"
                        :opciones="['BAJO' => 'Bajo el mínimo', 'AGOTADO' => 'Agotados']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('productos.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" :href="route('productos.create')">Nuevo producto</x-ui.button>
                </div>

                {{-- El filtro de estado va aparte: casi siempre se quiere ver solo el catálogo vigente. --}}
                <input type="hidden" name="estado" value="{{ $filtros['estado'] }}" />
            </form>
        </div>

        {{-- Tabla --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="nombre" defecto>Producto</x-tabla.th>
                            {{-- En el teléfono quedan producto, estante, stock y acciones:
                                 lo que hace falta para reconocer y reponer. --}}
                            <x-tabla.th clave="categoria" class="hidden md:table-cell">Categoría</x-tabla.th>
                            <x-tabla.th clave="compra" inicial="desc" derecha class="hidden lg:table-cell">Compra</x-tabla.th>
                            <x-tabla.th clave="venta" inicial="desc" derecha
                                @class(['hidden lg:table-cell' => $tasa > 0])>
                                {{ $tasa > 0 ? 'Venta (base)' : 'Venta' }}
                            </x-tabla.th>
                            @if ($tasa > 0)
                                <x-tabla.th clave="estante" inicial="desc" derecha>Estante</x-tabla.th>
                            @endif
                            <x-tabla.th clave="stock" inicial="desc" derecha>Stock</x-tabla.th>
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
                                                @if ($producto->codigo_barras)
                                                    · {{ $producto->codigo_barras }}
                                                @endif
                                            </span>
                                            @unless ($producto->activo)
                                                <x-ui.estado estado="CESADO" texto="Descatalogado" class="ml-1" />
                                            @endunless
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $producto->categoria?->nombre }}
                                </td>
                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ Config::importe($producto->precio_compra) }}
                                </td>
                                <td
                                    class="@if ($tasa > 0) hidden text-gray-500 lg:table-cell dark:text-gray-400 @else font-medium text-gray-800 dark:text-white/90 @endif px-5 py-4 text-right whitespace-nowrap text-theme-sm">
                                    {{ Config::importe($producto->precio_venta) }}
                                    @if ($producto->margen_porcentaje !== null)
                                        <span class="block text-theme-xs text-success-700 dark:text-success-500">
                                            {{ $producto->margen_porcentaje }}% margen
                                        </span>
                                    @endif
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
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <span
                                        class="font-medium text-theme-sm {{ $producto->bajo_minimo ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                                        {{ Config::cantidad($producto->stock_actual) }}
                                        {{ $producto->unidadMedida?->codigo }}
                                    </span>
                                    <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                        mín. {{ Config::cantidad($producto->stock_minimo) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('productos.show', $producto) }}" title="Ver ficha y kardex"
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
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No se encontraron productos con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$productos" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            @if (Config::tasaImpuesto() > 0)
                Los precios de compra y de venta se registran <b>sin impuesto</b>. La columna «Estante» es lo que
                paga el cliente: precio base más el {{ number_format(Config::tasaImpuesto() * 100, 0) }}% de impuesto.
            @else
                El precio de venta es <b>el precio final</b>: es lo que paga el cliente, tal cual. El «margen» es la
                ganancia por unidad —venta menos compra— como porcentaje del precio de venta.
            @endif
        </p>
    </div>
@endsection
