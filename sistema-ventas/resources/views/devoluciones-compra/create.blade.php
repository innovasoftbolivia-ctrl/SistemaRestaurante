@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();

    // Cada línea de la compra, con lo que todavía se puede devolver y sus
    // tandas abiertas. Se arma en PHP para que la pantalla no tenga que
    // recalcular nada que el servidor ya sabe.
    $filas = $compra->detalle->map(fn ($l) => [
        'id' => $l->id,
        'producto_id' => $l->producto_id,
        'producto' => $l->producto?->nombre,
        'codigo' => $l->producto?->codigo,
        'unidad' => $l->producto?->unidadMedida?->codigo,
        'paso' => $l->producto?->unidadMedida?->permite_decimal ? 0.001 : 1,
        'comprado' => (float) $l->cantidad,
        'devuelto' => (float) $l->cantidad_devuelta,
        'pendiente' => $l->pendiente_devolucion,
        'costo' => (float) $l->costo_unitario,
        'controlaVencimiento' => (bool) $l->producto?->controla_vencimiento,
        'lotes' => ($lotes[$l->producto_id] ?? collect())->map(fn ($lote) => [
            'id' => $lote->id,
            'etiqueta' => ($lote->fecha_vencimiento
                    ? 'vence '.$lote->fecha_vencimiento->format('d/m/Y')
                    : 'sin fecha')
                .' · quedan '.Config::cantidad($lote->cantidad_actual)
                .($lote->codigo ? ' · '.$lote->codigo : ''),
        ])->values(),
    ])->values();
@endphp

@section('content')
    <form method="POST" action="{{ route('devoluciones-compra.store', $compra) }}" class="space-y-6"
        x-data="devolucionNueva(@js($filas), @js($loteElegido))"
        @submit="if (!hayLineas) { $event.preventDefault(); avisoSinLineas = true; }">
        @csrf

        @if ($errors->any())
            <x-ui.alert variant="error" title="La devolución no se pudo registrar" :message="$errors->first()" />
        @endif

        {{-- ------------------------------------------- de qué compra sale --}}
        <x-common.component-card title="De qué compra"
            desc="Solo se puede devolver lo que trajo esta factura, y solo lo que todavía no se devolvió antes.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                    <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Factura</p>
                    <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ $compra->documento_externo ?: 'Compra #'.$compra->id }}
                    </p>
                    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $compra->proveedor?->razon_social }} · {{ $compra->fecha?->format('d/m/Y') }}
                    </p>
                </div>

                <x-form.campo label="¿Por qué se devuelve?" for="motivo" name="motivo" required
                    help="Es lo que después permite contar cuánto se devolvió por vencimiento y cuánto por fallas.">
                    {{-- Si se llegó desde vencimientos, el motivo ya se sabe. --}}
                    <x-form.select id="motivo" name="motivo"
                        :value="old('motivo', $loteElegido ? 'VENCIMIENTO' : null)"
                        placeholder="Elige el motivo" :opciones="$motivos" required />
                </x-form.campo>
            </div>

            {{-- El cambio no es otro formulario: es esta casilla. --}}
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <x-form.check name="con_reposicion" model="conReposicion"
                    label="El proveedor lo repone (cambio)" />
                <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    <span x-show="conReposicion" x-cloak>
                        Sale lo fallado y entra lo repuesto, todo en este mismo documento: el stock queda como
                        estaba, pero registrado. Si lo repuesto trae otra fecha de vencimiento, anótala en la línea.
                    </span>
                    <span x-show="!conReposicion" x-cloak>
                        La mercadería se va y el stock baja. Se espera la nota de crédito del proveedor.
                    </span>
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.campo label="Nota de crédito o guía" for="documento_externo" name="documento_externo"
                    help="El número del papel con el que se va la mercadería.">
                    <x-form.input id="documento_externo" name="documento_externo" :value="old('documento_externo')"
                        placeholder="NC-00045" maxlength="30" />
                </x-form.campo>

                <x-form.campo label="Observación" for="observacion" name="observacion">
                    <x-form.input id="observacion" name="observacion" :value="old('observacion')"
                        placeholder="Opcional" maxlength="255" />
                </x-form.campo>
            </div>
        </x-common.component-card>

        {{-- --------------------------------------------- qué se devuelve --}}
        <x-common.component-card title="Qué se devuelve"
            desc="Marca los productos y pon cuánto vuelve. Lo que no marques se queda como está.">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-3 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Se puede devolver</th>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Devuelvo</th>
                            <th class="px-3 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="(l, i) in filas" :key="l.id">
                            <tr>
                                <td class="px-3 py-4 align-top">
                                    {{-- Solo viajan las líneas con cantidad: marcar
                                         todas y mandar ceros haría fallar la
                                         validación por una línea que nadie quiso
                                         devolver. --}}
                                    <template x-if="l.cantidad > 0">
                                        <span>
                                            <input type="hidden" :name="`lineas[${i}][compra_detalle_id]`" :value="l.id" />
                                            <input type="hidden" :name="`lineas[${i}][cantidad]`" :value="l.cantidad" />
                                            <input type="hidden" :name="`lineas[${i}][lote_id]`" :value="l.loteId || ''" />
                                            <input type="hidden" :name="`lineas[${i}][vence_repuesto]`" :value="conReposicion ? (l.venceRepuesto || '') : ''" />
                                        </span>
                                    </template>

                                    <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="l.producto"></span>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400" x-text="l.codigo"></span>
                                </td>

                                <td class="px-3 py-4 text-right align-top text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span x-text="l.pendiente + ' ' + l.unidad"></span>
                                    <span class="block text-theme-xs" x-show="l.devuelto > 0"
                                        x-text="'ya se devolvieron ' + l.devuelto"></span>
                                </td>

                                <td class="px-3 py-4 align-top">
                                    <div class="w-32">
                                        <x-form.input type="number" min="0" x-bind:max="l.pendiente"
                                            x-bind:step="l.paso" x-model.number="l.cantidad" placeholder="0" />
                                    </div>

                                    {{-- La tanda: devolver lo vencido significa devolver
                                         ESE lote, no el que saldría primero. --}}
                                    <div x-show="l.cantidad > 0 && l.controlaVencimiento && l.lotes.length"
                                        x-cloak class="mt-2 w-56">
                                        <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">¿De qué tanda?</span>
                                        <x-form.select x-model="l.loteId">
                                            <option value="">La que vence antes</option>
                                            <template x-for="lo in l.lotes" :key="lo.id">
                                                <option :value="lo.id" x-text="lo.etiqueta"></option>
                                            </template>
                                        </x-form.select>
                                    </div>

                                    <div x-show="l.cantidad > 0 && conReposicion && l.controlaVencimiento"
                                        x-cloak class="mt-2 w-44">
                                        <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Lo repuesto vence el</span>
                                        <x-form.input type="date" x-model="l.venceRepuesto" />
                                    </div>
                                </td>

                                <td class="px-3 py-4 text-right align-top whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $moneda }} <span x-text="importe(l).toFixed(2)"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-common.component-card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2"></div>

            <x-common.component-card title="Guardar">
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                    <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Total devuelto</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $moneda }} <span x-text="total.toFixed(2)"></span>
                    </p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        <span x-text="cuantas"></span>
                        <span x-text="cuantas === 1 ? 'producto' : 'productos'"></span>
                    </p>
                </div>

                <div x-show="avisoSinLineas" x-cloak>
                    <x-ui.alert variant="warning" title="No marcaste nada"
                        message="Pon una cantidad en al menos un producto." />
                </div>

                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit" x-bind:disabled="!hayLineas">Registrar devolución</x-ui.button>
                    <x-ui.button variant="outline" :href="route('compras.show', $compra)">Cancelar</x-ui.button>
                </div>

                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Cada línea deja su movimiento en el kardex con este documento. Una devolución registrada no se
                    edita: una corrección es un ajuste de inventario.
                </p>
            </x-common.component-card>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function devolucionNueva(filas, elegido) {
            return {
                conReposicion: false,
                avisoSinLineas: false,

                /* Cada fila arranca en cero: se devuelve lo que se marca, no
                   todo lo que trajo la factura.

                   Salvo que se haya llegado desde vencimientos con una tanda
                   señalada: esa línea viene con su cantidad y su lote puestos,
                   topada en lo que la factura todavía permite devolver. */
                filas: filas.map((f) => {
                    const suya = elegido && elegido.producto_id === f.producto_id;

                    return {
                        ...f,
                        cantidad: suya ? Math.min(elegido.cantidad, f.pendiente) : null,
                        loteId: suya ? String(elegido.lote_id) : '',
                        venceRepuesto: '',
                    };
                }),

                importe(l) {
                    const cantidad = Math.min(Number(l.cantidad) || 0, l.pendiente);

                    return Math.round(cantidad * l.costo * 100) / 100;
                },

                get total() {
                    return Math.round(this.filas.reduce((s, l) => s + this.importe(l), 0) * 100) / 100;
                },

                get cuantas() {
                    return this.filas.filter((l) => (Number(l.cantidad) || 0) > 0).length;
                },

                get hayLineas() {
                    return this.cuantas > 0;
                },
            };
        }
    </script>
@endpush
