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
                        <x-ui.estado
                            :estado="match ($devolucion->espera) {
                                'REPUESTO' => 'ACTIVO',
                                'PENDIENTE' => 'SUSPENDIDO',
                                default => 'CESADO',
                            }"
                            :texto="$devolucion->etiqueta_espera" />
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
                    $devolucion->espera === 'REPUESTO'
                        ? ['Efecto en el stock', 'Ninguno', 'text-gray-800 dark:text-white/90', 'salió y volvió a entrar']
                        : ($devolucion->pendiente_reposicion > 0
                            ? ['El proveedor debe', Config::cantidad($devolucion->pendiente_reposicion),
                                'text-warning-600 dark:text-warning-400', 'unidades sin reponer']
                            : ['Efecto en el stock',
                                '− '.Config::cantidad($devolucion->detalle->sum(fn ($l) => (float) $l->cantidad)),
                                'text-error-600 dark:text-error-400', 'unidades que se fueron']),
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
                            @foreach (['Producto', 'Tanda', 'Cantidad', 'Repuesto', 'Costo', 'Importe'] as $i => $columna)
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
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm">
                                    @if ($devolucion->espera === 'NOTA_CREDITO')
                                        <span class="text-gray-400 dark:text-gray-600">—</span>
                                    @elseif ($linea->pendiente_reposicion > 0)
                                        <span class="text-warning-600 dark:text-warning-400">
                                            {{ Config::cantidad($linea->cantidad_repuesta) }}
                                            · faltan {{ Config::cantidad($linea->pendiente_reposicion) }}
                                        </span>
                                    @else
                                        <span class="text-success-700 dark:text-success-500">completo</span>
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
                            <td colspan="5" class="px-5 py-4 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
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

        @if ($devolucion->espera === 'PENDIENTE' && $devolucion->pendiente_reposicion > 0)
            @puede('inventario.ingresar')
                @php
                    $faltan = $devolucion->detalle
                        ->filter(fn ($l) => $l->pendiente_reposicion > 0)
                        ->map(fn ($l) => [
                            'id' => $l->id,
                            'producto' => $l->producto?->nombre,
                            'unidad' => $l->producto?->unidadMedida?->codigo,
                            'paso' => $l->producto?->unidadMedida?->permite_decimal ? 0.001 : 1,
                            'falta' => $l->pendiente_reposicion,
                            'controlaVencimiento' => (bool) $l->producto?->controla_vencimiento,
                        ])->values();
                @endphp

                <form method="POST" action="{{ route('devoluciones-compra.reponer', $devolucion) }}"
                    x-data="reposicion(@js($faltan))">
                    @csrf

                    <x-common.component-card title="El proveedor trajo el reemplazo"
                        desc="Anota lo que llegó. Puede venir en partes: lo que no pongas queda como pendiente.">
                        <div class="max-w-full overflow-x-auto overscroll-x-contain">
                            <table class="min-w-full">
                                <thead class="border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                                        <th class="px-3 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Falta</th>
                                        <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Trajo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <template x-for="(l, i) in filas" :key="l.id">
                                        <tr>
                                            <td class="px-3 py-4 align-top">
                                                <template x-if="l.cantidad > 0">
                                                    <span>
                                                        <input type="hidden" :name="`lineas[${i}][linea_id]`" :value="l.id" />
                                                        <input type="hidden" :name="`lineas[${i}][cantidad]`" :value="l.cantidad" />
                                                        <input type="hidden" :name="`lineas[${i}][vence]`" :value="l.vence || ''" />
                                                    </span>
                                                </template>
                                                <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="l.producto"></span>
                                            </td>
                                            <td class="px-3 py-4 text-right align-top text-theme-sm text-gray-500 dark:text-gray-400">
                                                <span x-text="l.falta + ' ' + l.unidad"></span>
                                            </td>
                                            <td class="px-3 py-4 align-top">
                                                <div class="w-32">
                                                    <x-form.input type="number" min="0" x-bind:max="l.falta"
                                                        x-bind:step="l.paso" x-model.number="l.cantidad" placeholder="0" />
                                                </div>

                                                {{-- Lo repuesto abre su propia tanda: el
                                                     reemplazo de algo vencido viene, por
                                                     definición, con otra fecha. --}}
                                                <div x-show="l.cantidad > 0 && l.controlaVencimiento" x-cloak class="mt-2 w-44">
                                                    <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Vence el</span>
                                                    <x-form.input type="date" x-model="l.vence" />
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <x-form.campo label="Guía o nota de entrega" for="rep_documento" name="documento_externo"
                                help="El papel con el que llegó el reemplazo, si trae uno.">
                                <x-form.input id="rep_documento" name="documento_externo" maxlength="30"
                                    placeholder="{{ $devolucion->documento_externo ?: 'G-00120' }}" />
                            </x-form.campo>

                            <div class="flex items-end">
                                <x-ui.button type="submit" size="sm" x-bind:disabled="!hayLineas">
                                    Registrar lo que trajo
                                </x-ui.button>
                            </div>
                        </div>
                    </x-common.component-card>
                </form>
            @endpuede
        @endif

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            @switch($devolucion->espera)
                @case('REPUESTO')
                    Cada línea dejó dos movimientos en el kardex —la salida de lo devuelto y la entrada de lo
                    repuesto—, así que el stock quedó igual pero el problema quedó registrado.
                    @break
                @case('PENDIENTE')
                    La mercadería salió y el proveedor debe reponerla. Cuando llegue, se anota aquí y entra al
                    stock contra esta misma devolución: así se puede saber en cualquier momento qué falta.
                    @break
                @default
                    Cada línea dejó su salida en el kardex. No se espera que vuelva nada: la devolución queda a
                    cuenta con el proveedor.
            @endswitch
        </p>
    </div>
@endsection

@push('scripts')
    <script>
        function reposicion(filas) {
            return {
                /* Arranca en cero: se registra lo que el proveedor trajo de
                   verdad, no lo que debía. Traer de menos es lo normal. */
                filas: filas.map((f) => ({ ...f, cantidad: null, vence: '' })),

                get hayLineas() {
                    return this.filas.some((l) => (Number(l.cantidad) || 0) > 0);
                },
            };
        }
    </script>
@endpush
