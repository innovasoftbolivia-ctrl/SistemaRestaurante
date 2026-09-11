@extends('layouts.app')

@php
    use App\Support\Config;

    $venta = $devolucion->venta;
    $sinReingreso = $devolucion->detalle->where('reingresa_stock', false);
@endphp

@section('content')
    <div class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="mb-2 flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            Devolución #{{ $devolucion->id }}
                        </h2>
                        <x-ui.estado :estado="$devolucion->tipo === 'TOTAL' ? 'CESADO' : 'SUSPENDIDO'"
                            :texto="$devolucion->tipo === 'TOTAL' ? 'Devolución total' : 'Devolución parcial'" />
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $devolucion->fecha?->format('d/m/Y H:i') }} ·
                        registrada por {{ $devolucion->usuario?->usuario }} ·
                        {{ $devolucion->sesionCaja?->caja?->nombre }}
                    </p>
                    <p class="mt-3 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">
                        <b class="text-gray-800 dark:text-white/90">Motivo:</b> {{ $devolucion->motivo }}
                    </p>
                </div>

                <x-ui.button size="sm" variant="outline" :href="route('ventas.show', $venta)">
                    Ver la venta original
                </x-ui.button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $cifras = [
                    ['Devuelto', Config::importe($devolucion->total), 'text-error-600 dark:text-error-400', 'salió del cajón'],
                    ['Líneas', $devolucion->detalle->count(), 'text-gray-800 dark:text-white/90', null],
                    ['Volvió al estante', $devolucion->detalle->count() - $sinReingreso->count(),
                        'text-success-700 dark:text-success-500', 'reingresó al inventario'],
                    ['No reingresó', $sinReingreso->count(),
                        $sinReingreso->count() ? 'text-warning-700 dark:text-orange-400' : 'text-gray-800 dark:text-white/90',
                        'mercadería dañada'],
                ];
            @endphp

            @foreach ($cifras as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="px-6 py-5">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Mercadería devuelta</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Al precio y con la tasa de impuesto del día en que se vendió, no los de hoy.
                        </p>
                    </div>

                    <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cantidad</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">P. unitario</th>
                                    <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Inventario</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($devolucion->detalle as $linea)
                                    <tr>
                                        <td class="px-5 py-4">
                                            <a href="{{ route('productos.show', $linea->producto_id) }}"
                                                class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                                {{ $linea->ventaDetalle?->descripcion ?? $linea->producto?->nombre }}
                                            </a>
                                            <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $linea->producto?->codigo }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ Config::cantidad($linea->cantidad) }}
                                            {{-- La de la venta, no la de hoy: la devolución habla
                                                 de lo que se vendió entonces. --}}
                                            {{ $linea->ventaDetalle?->unidad ?? $linea->producto?->unidadMedida?->codigo }}
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ Config::importe($linea->precio_unitario) }}
                                            @if ($linea->afecto_impuesto)
                                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                    + {{ Config::importe($linea->impuesto_linea) }} imp.
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4">
                                            <x-ui.estado :estado="$linea->reingresa_stock ? 'ACTIVO' : 'SUSPENDIDO'"
                                                :texto="$linea->reingresa_stock ? 'Volvió al estante' : 'No reingresó'" />
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ Config::importe($linea->total_linea) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-2 border-t border-gray-100 px-6 py-5 dark:border-gray-800">
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Base (sin impuesto)</span>
                            <span>{{ Config::importe($devolucion->base) }}</span>
                        </div>
                        @if ($devolucion->impuesto_devuelto > 0)
                            <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                                <span>Impuesto reintegrado</span>
                                <span>{{ Config::importe($devolucion->impuesto_devuelto) }}</span>
                            </div>
                        @endif
                        <div class="flex items-baseline justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                            <span class="font-medium text-gray-800 dark:text-white/90">Devuelto al cliente</span>
                            <span class="text-title-sm font-semibold text-error-600 dark:text-error-400">
                                {{ Config::importe($devolucion->total) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <x-common.component-card title="Venta original">
                    <dl class="space-y-3">
                        @foreach ([
                            'Comprobante' => $venta?->comprobante?->numero_completo ?? '#'.$devolucion->venta_id,
                            'Fecha' => $venta?->fecha?->format('d/m/Y H:i'),
                            'Cliente' => $venta?->cliente?->nombre ?? 'Cliente varios',
                            'Total de la venta' => Config::importe($venta?->total),
                            'Devuelto en total' => Config::importe($venta?->total_devuelto),
                        ] as $etiqueta => $valor)
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $etiqueta }}
                                </dt>
                                <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $valor }}</dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Estado de la venta
                            </dt>
                            <dd>
                                <x-ui.estado :estado="$venta?->estado === 'DEVUELTA' ? 'CESADO' : 'SUSPENDIDO'"
                                    :texto="ucfirst(str_replace('_', ' ', mb_strtolower($venta?->estado ?? '')))" />
                            </dd>
                        </div>
                    </dl>
                </x-common.component-card>

                @if ($venta?->admiteDevolucion())
                    @puede('devoluciones.registrar')
                        <x-common.component-card title="Queda por devolver"
                            desc="Esta venta todavía tiene líneas sin devolver.">
                            <x-ui.button size="sm" variant="outline" class="w-full"
                                :href="route('devoluciones.create', $venta)">
                                Registrar otra devolución
                            </x-ui.button>
                        </x-common.component-card>
                    @endpuede
                @endif
            </div>
        </div>
    </div>
@endsection
