@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Cabecera: lo que dice el papel del proveedor --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ $compra->documento_externo ?: 'Compra #'.$compra->id }}
                    </h2>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $compra->proveedor?->razon_social }}
                        @if ($compra->proveedor?->documento)
                            · NIT {{ $compra->proveedor->documento }}
                        @endif
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.estado estado="INDEFINIDO" :texto="$compra->fecha?->format('d/m/Y H:i')" />
                        <x-ui.estado estado="PRACTICAS"
                            :texto="$compra->detalle->count().' '.($compra->detalle->count() === 1 ? 'producto' : 'productos')" />
                        <x-ui.estado estado="ACTIVO" :texto="'Registró '.$compra->usuario?->usuario" />
                    </div>
                    @if ($compra->observacion)
                        <p class="mt-3 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">
                            {{ $compra->observacion }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="outline"
                        :href="route('inventario.movimientos', ['origen' => 'COMPRA'])">Ver en el kardex</x-ui.button>
                    @puede('inventario.ingresar')
                        <x-ui.button size="sm" :href="route('compras.create')">Registrar otra</x-ui.button>
                    @endpuede
                </div>
            </div>
        </div>

        {{-- El total es la cifra que se contrasta contra el papel. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $unidades = $compra->detalle->sum(fn ($l) => (float) $l->cantidad);
                $cifras = [
                    ['Total de la compra', Config::importe($compra->total), 'text-gray-800 dark:text-white/90', 'sin impuesto'],
                    ['Líneas', number_format($compra->detalle->count()), 'text-gray-800 dark:text-white/90', 'productos distintos'],
                    ['Unidades', Config::cantidad($unidades), 'text-gray-800 dark:text-white/90', 'sumando todas las líneas'],
                    ['Entró al stock', 'Sí', 'text-success-700 dark:text-success-500', 'cada línea dejó su kardex'],
                ];
            @endphp

            @foreach ($cifras as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        {{-- El detalle, línea por línea --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Lo que decía la factura. El stock que provocó cada línea está en el kardex de su producto.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Producto', 'Cantidad', 'Costo unitario', 'Importe'] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $i === 0 ? 'text-left' : 'text-right' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($compra->detalle as $linea)
                            @php($producto = $linea->producto)
                            <tr>
                                <td class="px-5 py-4">
                                    <a href="{{ route('productos.show', $linea->producto_id) }}"
                                        class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $producto?->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $producto?->codigo }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($linea->cantidad) }} {{ $producto?->unidadMedida?->codigo }}
                                    {{-- Cuántas cajas son esas unidades: es lo que
                                         permite cuadrar la línea contra la factura,
                                         que viene escrita en cajas. --}}
                                    @if ($producto?->desglosar($linea->cantidad))
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $producto->desglosar($linea->cantidad) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($linea->costo_unitario) }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($linea->importe) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-gray-200 dark:border-gray-700">
                        <tr>
                            <td colspan="3" class="px-5 py-4 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                Total
                            </td>
                            <td class="px-5 py-4 text-right whitespace-nowrap text-base font-bold text-gray-800 dark:text-white/90">
                                {{ Config::importe($compra->total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            Esta compra no se edita ni se borra: ya movió el stock. Si una línea se cargó mal, se corrige con un
            ajuste de inventario sobre ese producto, que deja la diferencia explicada y con responsable.
        </p>
    </div>
@endsection
