@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                        Devolución #{{ $devolucion->id }}
                        @if ($devolucion->documento_externo)
                            · {{ $devolucion->documento_externo }}
                        @endif
                    </h2>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $devolucion->compra?->proveedor?->razon_social }} ·
                        de la compra
                        <a href="{{ route('compras.show', $devolucion->compra_id) }}"
                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400">
                            {{ $devolucion->compra?->documento_externo ?: 'Compra #'.$devolucion->compra_id }}
                        </a>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.estado estado="INDEFINIDO" :texto="$devolucion->fecha?->format('d/m/Y H:i')" />
                        <x-ui.estado estado="SUSPENDIDO" :texto="$devolucion->etiqueta_motivo" />
                        <x-ui.estado :estado="$devolucion->con_reposicion ? 'ACTIVO' : 'CESADO'"
                            :texto="$devolucion->con_reposicion ? 'Cambio: lo repusieron' : 'Sin reposición'" />
                        <x-ui.estado estado="PRACTICAS" :texto="'Registró '.$devolucion->usuario?->usuario" />
                    </div>
                    @if ($devolucion->observacion)
                        <p class="mt-3 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">
                            {{ $devolucion->observacion }}
                        </p>
                    @endif
                </div>

                <x-ui.button size="sm" variant="outline"
                    :href="route('devoluciones-compra.index')">Ver todas</x-ui.button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
            @php
                $cifras = [
                    ['Total devuelto', Config::importe($devolucion->total), 'text-gray-800 dark:text-white/90', 'al costo de la compra'],
                    ['Líneas', number_format($devolucion->detalle->count()), 'text-gray-800 dark:text-white/90', 'productos distintos'],
                    ['Efecto en el stock',
                        $devolucion->con_reposicion ? 'Ninguno' : '− '.Config::cantidad($devolucion->detalle->sum(fn ($l) => (float) $l->cantidad)),
                        $devolucion->con_reposicion ? 'text-gray-800 dark:text-white/90' : 'text-error-600 dark:text-error-400',
                        $devolucion->con_reposicion ? 'salió y volvió a entrar' : 'unidades que se fueron'],
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

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle</h2>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Producto', 'Tanda', 'Cantidad', 'Costo', 'Importe'] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $i >= 2 ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($devolucion->detalle as $linea)
                            <tr>
                                <td class="px-5 py-4">
                                    <a href="{{ route('productos.show', $linea->producto_id) }}"
                                        class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $linea->producto?->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $linea->producto?->codigo }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-theme-xs text-gray-500 dark:text-gray-400">
                                    @if ($linea->lote)
                                        {{ $linea->lote->fecha_vencimiento
                                            ? 'vence '.$linea->lote->fecha_vencimiento->format('d/m/Y')
                                            : 'sin fecha' }}
                                        @if ($linea->lote->codigo)
                                            · {{ $linea->lote->codigo }}
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($linea->cantidad) }} {{ $linea->producto?->unidadMedida?->codigo }}
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
                            <td colspan="4" class="px-5 py-4 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                Total
                            </td>
                            <td class="px-5 py-4 text-right whitespace-nowrap text-base font-bold text-gray-800 dark:text-white/90">
                                {{ Config::importe($devolucion->total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            @if ($devolucion->con_reposicion)
                Fue un cambio: cada línea dejó dos movimientos en el kardex —la salida de lo devuelto y la entrada
                de lo repuesto—, así que el stock quedó igual pero el problema quedó registrado.
            @else
                Cada línea dejó su salida en el kardex. Si el proveedor termina reponiendo la mercadería, cárgala
                como un ingreso normal citando esta devolución.
            @endif
        </p>
    </div>
@endsection
