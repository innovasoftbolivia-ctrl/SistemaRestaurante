@extends('layouts.app')

@php
    use App\Models\DevolucionCompra;
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Cabecera: lo que dice el papel del proveedor --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ $compra->proveedor?->razon_social }}
                    </h2>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $compra->documento_externo ? 'Factura '.$compra->documento_externo : 'Sin número de factura' }}
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

                <div class="flex flex-col items-start gap-3 lg:items-end">
                    <div class="lg:text-right">
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
                        <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ Config::importe($compra->total) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        {{-- Se devuelve desde la compra: así nunca se le devuelve
                             al proveedor equivocado. Sin nada por devolver, no hay botón. --}}
                        @if ($sePuedeDevolver)
                            <x-ui.button size="sm" variant="outline"
                                :href="route('devoluciones-compra.create', $compra)">Devolver al proveedor</x-ui.button>
                        @endif
                        <x-ui.button size="sm" :href="route('compras.create')">Registrar otra</x-ui.button>
                    </div>
                </div>
            </div>
        </div>

        {{-- El detalle, línea por línea --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Cantidades en unidades de venta; debajo, cómo se cuentan en cajas.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th derecha>Cantidad</x-tabla.th>
                            <x-tabla.th derecha>Costo unitario</x-tabla.th>
                            <x-tabla.th derecha>Importe</x-tabla.th>
                            <x-tabla.th derecha>Devuelto</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($compra->detalle as $linea)
                            @php
                                $producto = $linea->producto;
                                // El desglose en cajas lo sabe decir el producto sobre
                                // su stock: se le pide sobre la cantidad de la línea.
                                $enEmpaques = $producto && (float) $producto->contenido_empaque > 1
                                    ? $producto->enEmpaques((float) $linea->cantidad)
                                    : null;
                            @endphp
                            <tr>
                                <td class="px-5 py-4">
                                    <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                        {{ $producto?->nombre }}
                                    </span>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $producto?->codigo }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($linea->cantidad) }} u.
                                    @if ($enEmpaques)
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400">{{ $enEmpaques }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($linea->costo_unitario) }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($linea->importe) }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm {{ (float) $linea->cantidad_devuelta > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400' }}">
                                    {{ (float) $linea->cantidad_devuelta > 0 ? Config::cantidad($linea->cantidad_devuelta).' u.' : '—' }}
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
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @if ($compra->devoluciones->isNotEmpty())
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Devoluciones al proveedor</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Lo que se le devolvió de esta compra. Cada devolución bajó el stock.
                    </p>
                </div>

                <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($compra->devoluciones as $devolucion)
                        <a href="{{ route('devoluciones-compra.show', $devolucion) }}"
                            class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <span>
                                <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    Devolución #{{ $devolucion->id }}
                                    @if ($devolucion->documento_externo)
                                        · {{ $devolucion->documento_externo }}
                                    @endif
                                </span>
                                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $devolucion->fecha?->format('d/m/Y H:i') }} ·
                                    {{ DevolucionCompra::MOTIVOS[$devolucion->motivo] ?? $devolucion->motivo }} ·
                                    {{ DevolucionCompra::ESPERAS[$devolucion->espera] ?? $devolucion->espera }}
                                </span>
                            </span>
                            <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ Config::importe($devolucion->total) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            Esta compra no se edita ni se borra: ya subió el stock. Si una línea se cargó mal, se corrige con un ajuste
            de inventario sobre ese producto.
        </p>
    </div>
@endsection
