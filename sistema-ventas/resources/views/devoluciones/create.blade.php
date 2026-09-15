@extends('layouts.app')

@php
    use App\Services\Devoluciones;
    use App\Support\Config;

    $moneda = Config::moneda();

    // Solo tienen sentido las líneas con algo pendiente de devolver.
    $lineas = $venta->detalle->filter(fn ($l) => $l->pendiente_devolucion > 0)->values();
@endphp

@section('content')
    @unless ($sesion)
        <div class="mx-auto max-w-xl">
            <x-common.component-card title="No tienes una caja abierta"
                desc="El dinero de la devolución sale del cajón, así que necesita un turno abierto donde imputarse.">
                <x-ui.button :href="route('caja.index')" class="w-full">Ir a caja</x-ui.button>
            </x-common.component-card>
        </div>
    @elseif ($lineas->isEmpty())
        <div class="mx-auto max-w-xl">
            <x-common.component-card title="No queda nada por devolver"
                desc="Todas las líneas de esta venta ya fueron devueltas.">
                <x-ui.button variant="outline" :href="route('ventas.show', $venta)" class="w-full">
                    Volver a la venta
                </x-ui.button>
            </x-common.component-card>
        </div>
    @else
        <form method="POST" action="{{ route('devoluciones.store', $venta) }}"
            x-data="devolucion(@js($lineas->map(fn ($l) => [
                'id' => $l->id,
                'nombre' => $l->descripcion,
                'codigo' => $l->producto?->codigo,
                'unidad' => $l->producto?->unidadMedida?->codigo,
                'decimal' => (bool) $l->producto?->unidadMedida?->permite_decimal,
                // Neto de descuento de cabecera: es el mismo precio que
                // Devoluciones::registrar() va a guardar y a descontar del
                // cajón, no el precio de catálogo sin prorratear — si no, en
                // una venta con descuento esta pantalla promete devolver más
                // dinero del que el sistema realmente registra.
                'precio' => Devoluciones::precioNetoUnitario($venta, $l),
                'tasa' => $l->afecto_impuesto ? (float) $l->tasa_impuesto : 0,
                'vendida' => (float) $l->cantidad,
                'devuelta' => (float) $l->cantidad_devuelta,
                'pendiente' => $l->pendiente_devolucion,
            ])))"
            class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            @csrf

            <div class="space-y-6 lg:col-span-2">
                <x-common.component-card title="Qué devuelve el cliente"
                    desc="Indica la cantidad de cada producto. Lo que no vuelve al estante no suma stock, pero sí se devuelve el dinero.">
                    <div class="space-y-4">
                        <template x-for="(l, i) in lineas" :key="l.id">
                            <div class="rounded-xl border p-4 transition"
                                :class="l.cantidad > 0
                                    ? 'border-brand-300 bg-brand-25 dark:border-brand-800 dark:bg-brand-500/5'
                                    : 'border-gray-200 dark:border-gray-800'">

                                <input type="hidden" :name="`lineas[${i}][venta_detalle_id]`" :value="l.id" />
                                <input type="hidden" :name="`lineas[${i}][cantidad]`" :value="l.cantidad" />
                                <input type="hidden" :name="`lineas[${i}][reingresa_stock]`"
                                    :value="l.reingresa ? '1' : '0'" />

                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                            x-text="l.nombre"></p>
                                        <p class="font-mono text-theme-xs text-gray-500 dark:text-gray-400"
                                            x-text="l.codigo + ' · {{ $moneda }} ' + l.precio.toFixed(2) + ' c/u'"></p>
                                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                            Vendidas <span x-text="texto(l.vendida)"></span>
                                            <template x-if="l.devuelta > 0">
                                                <span>· ya devueltas <span x-text="texto(l.devuelta)"></span></span>
                                            </template>
                                            · <b>pendiente <span x-text="texto(l.pendiente)"></span></b>
                                            <span x-text="l.unidad"></span>
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        {{-- Son varios inputs iguales, uno por línea: sin un nombre propio por
                                             fila, un lector de pantalla solo dice «0», sin decir de qué producto. --}}
                                        <input type="number" inputmode="decimal" min="0" :max="l.pendiente"
                                            :step="l.decimal ? '0.001' : '1'" x-model.number="l.cantidad"
                                            :aria-label="`Cantidad a devolver de ${l.nombre}`"
                                            @change="normalizar(i)" placeholder="0"
                                            class="dark:bg-dark-900 h-11 w-24 rounded-lg border border-gray-300 bg-transparent px-3 text-center text-sm text-gray-800 focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                        <button type="button" @click="l.cantidad = l.pendiente"
                                            class="rounded-lg border border-gray-200 px-3 py-2.5 text-theme-xs text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            Todo
                                        </button>
                                    </div>
                                </div>

                                <div x-show="l.cantidad > 0" x-cloak
                                    class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                                    <label class="flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 select-none dark:text-gray-400">
                                        <input type="checkbox" x-model="l.reingresa"
                                            class="h-4 w-4 rounded border-gray-300 text-brand-500 dark:text-brand-400 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900" />
                                        La mercadería vuelve al estante
                                    </label>
                                    <span class="text-theme-sm font-semibold text-gray-800 dark:text-white/90"
                                        x-text="'{{ $moneda }} ' + montos.importeLinea(l.precio, l.cantidad).toFixed(2)"></span>
                                </div>

                                <p x-show="l.cantidad > 0 && !l.reingresa" x-cloak
                                    class="mt-2 text-theme-xs text-warning-700 dark:text-orange-400">
                                    Se devuelve el dinero, pero el producto no vuelve al inventario (llegó dañado o
                                    vencido).
                                </p>
                            </div>
                        </template>
                    </div>
                </x-common.component-card>

                <x-common.component-card title="Motivo"
                    desc="Queda registrado con tu nombre. Es lo que explica la salida de dinero en el arqueo.">
                    <x-form.campo for="motivo" name="motivo" required>
                        <x-form.textarea id="motivo" name="motivo" rows="3"
                            placeholder="Producto en mal estado, el cliente se arrepintió, talla equivocada…"
                            required />
                    </x-form.campo>
                </x-common.component-card>
            </div>

            <div class="space-y-6">
                <x-common.component-card title="Venta original">
                    <dl class="space-y-3">
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Comprobante
                            </dt>
                            <dd class="font-mono text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $venta->comprobante?->numero_completo ?? 'venta #'.$venta->id }}
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Fecha</dt>
                            <dd class="text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $venta->fecha?->format('d/m/Y H:i') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Cliente</dt>
                            <dd class="text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $venta->cliente?->nombre ?? 'Cliente varios' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Total de la venta
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ Config::importe($venta->total) }}
                            </dd>
                        </div>
                        @if ((float) $venta->total_devuelto > 0)
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Ya devuelto
                                </dt>
                                <dd class="text-theme-sm font-medium text-error-600 dark:text-error-400">
                                    {{ Config::importe($venta->total_devuelto) }}
                                </dd>
                            </div>
                        @endif
                    </dl>
                </x-common.component-card>

                <x-common.component-card title="A devolver">
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Dinero que entregas al cliente
                        </p>
                        <p class="text-title-sm font-semibold text-error-600 dark:text-error-400">
                            {{ $moneda }} <span x-text="total.toFixed(2)"></span>
                        </p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            <span x-text="lineasElegidas"></span> línea(s) ·
                            {{ $sesion->caja?->nombre }}
                        </p>

                        <div x-show="impuesto > 0" x-cloak
                            class="mt-3 space-y-1 border-t border-gray-200 pt-3 dark:border-gray-700">
                            <div class="flex justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                                <span>Base</span>
                                <span>{{ $moneda }} <span x-text="base.toFixed(2)"></span></span>
                            </div>
                            <div class="flex justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                                <span>Impuesto reintegrado</span>
                                <span>{{ $moneda }} <span x-text="impuesto.toFixed(2)"></span></span>
                            </div>
                        </div>
                    </div>

                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Se devuelve lo que el cliente pagó: el precio y la tasa de impuesto del día de la venta, no los
                        de hoy. El arqueo de caja descuenta ese mismo importe. Esta versión no emite nota de crédito:
                        la devolución revierte stock y dinero, y queda auditada.
                    </p>

                    <div class="flex flex-col gap-3">
                        <x-ui.button type="submit" variant="danger" ::disabled="total <= 0">
                            Registrar devolución
                        </x-ui.button>
                        <x-ui.button variant="outline" :href="route('ventas.show', $venta)">Cancelar</x-ui.button>
                    </div>
                </x-common.component-card>
            </div>
        </form>

        @push('scripts')
            <script>
                function devolucion(lineas) {
                    return {
                        lineas: lineas.map(l => ({ ...l, cantidad: 0, reingresa: true })),

                        /* Nunca por encima de lo pendiente, y sin fracciones si la
                           unidad no las admite. */
                        normalizar(i) {
                            const l = this.lineas[i];
                            let c = Number(l.cantidad) || 0;

                            if (!l.decimal) c = Math.round(c);

                            l.cantidad = Math.min(Math.max(c, 0), l.pendiente);
                        },

                        texto(n) {
                            return Number(n).toFixed(3).replace(/\.?0+$/, '');
                        },

                        get base() {
                            return montos.sumar(this.lineas.map(l => montos.importeLinea(l.precio, l.cantidad)));
                        },

                        get impuesto() {
                            /* Redondeo por línea, igual que la columna generada
                               `impuesto_linea`. */
                            return montos.sumar(this.lineas.map(l => montos.impuestoDe(montos.importeLinea(l.precio, l.cantidad), l.tasa)));
                        },

                        /* Lo que la base guardará como total: con impuesto. */
                        get total() {
                            return this.redondear(this.base + this.impuesto);
                        },

                        redondear(n) {
                            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
                        },

                        get lineasElegidas() {
                            return this.lineas.filter(l => l.cantidad > 0).length;
                        },
                    };
                }
            </script>
        @endpush
    @endunless
@endsection
