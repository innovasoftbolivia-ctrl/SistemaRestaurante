@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Totales del recorte que se está viendo, no del inventario entero:
             la pregunta aquí es «cuánto entró y cuánto salió en esto que filtré». --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @php
                $tarjetas = [
                    ['Movimientos', number_format($resumen['movimientos']), 'text-gray-800 dark:text-white/90', 'en el filtro actual'],
                    ['Unidades que entraron', Config::cantidad($resumen['entraron']), 'text-success-700 dark:text-success-500', 'compras, devoluciones y anulaciones'],
                    ['Unidades que salieron', Config::cantidad($resumen['salieron']), 'text-error-600 dark:text-error-400', 'ventas y ajustes a la baja'],
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
            <form method="GET" action="{{ route('inventario.movimientos') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Producto" for="producto">
                        <x-form.select id="producto" name="producto" :value="$filtros['producto']"
                            placeholder="Todos" :opciones="$productos" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Tipo de movimiento" for="origen">
                    <x-form.select id="origen" name="origen" :value="$filtros['origen']"
                        placeholder="Todos" :opciones="$origenes" />
                </x-form.campo>

                <x-form.campo label="Responsable" for="usuario">
                    <x-form.select id="usuario" name="usuario" :value="$filtros['usuario']"
                        placeholder="Todos" :opciones="$usuarios" />
                </x-form.campo>

                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date"
                        :value="$filtros['desde']?->toDateString()" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date"
                        :value="$filtros['hasta']?->toDateString()" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm"
                        :href="route('inventario.movimientos')">Limpiar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" class="ml-auto"
                        :href="route('inventario.index')">Volver a existencias</x-ui.button>
                </div>
            </form>
        </div>

        {{-- El kardex --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Fecha</x-tabla.th>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th>Movimiento</x-tabla.th>
                            <x-tabla.th derecha>Cantidad</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Stock</x-tabla.th>
                            <x-tabla.th class="hidden lg:table-cell">Documento</x-tabla.th>
                            <x-tabla.th class="hidden md:table-cell">Responsable</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($movimientos as $movimiento)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $movimiento->fecha?->format('d/m/Y') }}
                                    <span class="block text-theme-xs">{{ $movimiento->fecha?->format('H:i') }}</span>
                                </td>

                                <td class="px-5 py-4 text-theme-sm">
                                    <a href="{{ route('productos.show', $movimiento->producto_id) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $movimiento->producto?->nombre }}
                                    </a>
                                    <span class="block font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->producto?->codigo }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $movimiento->etiqueta_origen }}
                                    @if ($movimiento->motivo)
                                        <span class="block text-theme-xs">{{ $movimiento->motivo }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm">
                                    <span
                                        class="font-medium {{ $movimiento->variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                        {{ $movimiento->variacion > 0 ? '+' : '' }}{{ Config::cantidad($movimiento->variacion) }}
                                    </span>
                                    <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->producto?->unidadMedida?->codigo }}
                                    </span>
                                </td>

                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ Config::cantidad($movimiento->stock_anterior) }} →
                                    <b class="text-gray-800 dark:text-white/90">{{ Config::cantidad($movimiento->stock_resultante) }}</b>
                                </td>

                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    @if ($movimiento->documento_externo)
                                        <span class="font-mono text-theme-xs">{{ $movimiento->documento_externo }}</span>
                                    @endif
                                    @if ($movimiento->proveedor)
                                        <span class="block text-theme-xs">{{ $movimiento->proveedor->razon_social }}</span>
                                    @endif
                                    @if ($movimiento->venta_id)
                                        <a href="{{ route('ventas.show', $movimiento->venta_id) }}"
                                            class="text-theme-xs hover:text-brand-500">Venta #{{ $movimiento->venta_id }}</a>
                                    @endif
                                </td>

                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $movimiento->usuario?->usuario }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay movimientos con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$movimientos" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            El kardex no se edita ni se borra: si un movimiento salió mal, la corrección es otro movimiento encima.
            Por eso las columnas «Stock» permiten rehacer la cuenta desde el principio y comprobar que no falta nada.
        </p>
    </div>
@endsection
