@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();
@endphp

@section('content')
    {{-- Toda la pantalla es un solo formulario. Las líneas viven en Alpine y se
         envían como campos ocultos: no hay guardado por partes ni borradores a
         medias, porque una factura entra entera o no entra. --}}
    <form method="POST" action="{{ route('compras.store') }}" class="space-y-6" x-data="compraNueva()"
        @submit="if (!lineas.length) { $event.preventDefault(); avisoSinLineas = true; }">
        @csrf

        @if ($errors->any())
            <x-ui.alert variant="error" title="La compra no se pudo registrar" :message="$errors->first()" />
        @endif

        {{-- ------------------------------------------------ el papel --}}
        <x-common.component-card title="La factura"
            desc="Lo que dice el papel que te dejó el proveedor. El número es opcional, pero es lo que después permite encontrar esta compra.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <x-form.campo label="Proveedor" for="proveedor_id" name="proveedor_id" required>
                    <x-form.select id="proveedor_id" name="proveedor_id" :value="old('proveedor_id')"
                        placeholder="Elige el proveedor" :opciones="$proveedores" required />
                </x-form.campo>

                <x-form.campo label="Guía o factura" for="documento_externo" name="documento_externo"
                    help="El número del documento.">
                    <x-form.input id="documento_externo" name="documento_externo" :value="old('documento_externo')"
                        placeholder="F001-00123" maxlength="30" />
                </x-form.campo>

                <x-form.campo label="Observación" for="observacion" name="observacion">
                    <x-form.input id="observacion" name="observacion" :value="old('observacion')"
                        placeholder="Opcional" maxlength="255" />
                </x-form.campo>
            </div>
        </x-common.component-card>

        {{-- --------------------------------------------- las líneas --}}
        <x-common.component-card title="Qué llegó"
            desc="Busca cada producto y anota lo que trae la factura. Si viene en cajas, cuenta cajas: el sistema multiplica.">

            {{-- El buscador es el mismo gesto del mostrador: teclear o pasar el
                 lector, y Enter agrega el primero. --}}
            <div class="relative">
                <x-form.campo label="Agregar producto" for="busqueda"
                    help="Nombre, código interno o código de barras. Enter agrega el primero de la lista.">
                    <x-form.input id="busqueda" x-model="busqueda" @input.debounce.250ms="buscar()"
                        @keydown.enter.prevent="agregarPrimero()" @keydown.escape="resultados = []"
                        placeholder="Arroz, P-0001 o 7750001000011" autocomplete="off" autofocus />
                </x-form.campo>

                <div x-show="resultados.length" x-cloak @click.outside="resultados = []"
                    class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
                    <template x-for="p in resultados" :key="p.id">
                        <button type="button" @click="agregar(p)"
                            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.05]">
                            <span class="min-w-0">
                                <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                    x-text="p.nombre"></span>
                                <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400" x-text="p.codigo"></span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400"
                                    x-text="'stock ' + p.stock + ' ' + p.unidad"></span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400"
                                    x-show="p.contenido > 0"
                                    x-text="p.empaque + ' de ' + p.contenido"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </div>

            <div x-show="!lineas.length" x-cloak
                class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-theme-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Todavía no agregaste ningún producto.
            </div>

            <div x-show="lineas.length" x-cloak class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cuánto llegó</th>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Costo</th>
                            <th class="px-3 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Importe</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="(l, i) in lineas" :key="l.id">
                            <tr>
                                {{-- Lo que de verdad se envía. El resto de la fila
                                     son ayudas para teclear. --}}
                                <td class="px-3 py-4 align-top">
                                    <input type="hidden" :name="`lineas[${i}][producto_id]`" :value="l.id" />
                                    <input type="hidden" :name="`lineas[${i}][cantidad]`" :value="cantidad(l)" />
                                    <input type="hidden" :name="`lineas[${i}][empaques]`" :value="l.contenido > 0 ? (l.empaques || 0) : ''" />
                                    <input type="hidden" :name="`lineas[${i}][sueltas]`" :value="l.contenido > 0 ? (l.sueltas || 0) : ''" />
                                    <input type="hidden" :name="`lineas[${i}][costo_unitario]`" :value="costoUnidad(l)" />
                                    <input type="hidden" :name="`lineas[${i}][actualizar_costo]`" :value="l.actualizarCosto ? 1 : 0" />

                                    <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="l.nombre"></span>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400" x-text="l.codigo"></span>
                                </td>

                                <td class="px-3 py-4 align-top">
                                    {{-- Con empaque: cajas y sueltas. Sin empaque: una
                                         sola casilla, como siempre. --}}
                                    <div x-show="l.contenido > 0" class="flex items-start gap-2">
                                        <label class="w-24">
                                            <span class="mb-1 block text-theme-xs capitalize text-gray-500 dark:text-gray-400"
                                                x-text="l.empaque"></span>
                                            <x-form.input type="number" step="1" min="0" x-model.number="l.empaques"
                                                placeholder="0" />
                                        </label>
                                        <label class="w-24">
                                            <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Sueltas</span>
                                            <x-form.input type="number" min="0" x-bind:step="l.paso"
                                                x-model.number="l.sueltas" placeholder="0" />
                                        </label>
                                    </div>

                                    <label x-show="!(l.contenido > 0)" class="block w-40">
                                        <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400"
                                            x-text="'Cantidad (' + l.unidad + ')'"></span>
                                        <x-form.input type="number" min="0" x-bind:step="l.paso"
                                            x-model.number="l.cantidadSuelta" placeholder="0" />
                                    </label>

                                    <span class="mt-1.5 block text-theme-xs text-gray-500 dark:text-gray-400"
                                        x-text="cantidad(l) + ' ' + l.unidad"></span>
                                </td>

                                <td class="px-3 py-4 align-top">
                                    <div class="flex items-start gap-2">
                                        <div class="w-28">
                                            <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Precio</span>
                                            <x-form.input type="number" step="0.01" min="0" x-model.number="l.costo" />
                                        </div>
                                        <div class="w-32" x-show="l.contenido > 0">
                                            <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">se cobra</span>
                                            <x-form.select x-model="l.costoPor">
                                                <option value="UNIDAD" x-text="'por ' + l.unidadNombre"></option>
                                                <option value="EMPAQUE" x-text="'por ' + l.empaque"></option>
                                            </x-form.select>
                                        </div>
                                    </div>

                                    {{-- Solo cuando el costo cambió: si es el de
                                         siempre, no hay nada que preguntar. --}}
                                    <label x-show="costoUnidad(l) !== l.costoAnterior" x-cloak
                                        class="mt-2 flex cursor-pointer items-center gap-2 text-theme-xs text-gray-500 dark:text-gray-400">
                                        <input type="checkbox" x-model="l.actualizarCosto"
                                            class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700" />
                                        <span>
                                            Actualizar el costo del producto
                                            (<span x-text="l.costoAnterior.toFixed(2)"></span> →
                                            <b x-text="costoUnidad(l).toFixed(2)"></b>)
                                        </span>
                                    </label>
                                </td>

                                <td class="px-3 py-4 text-right align-top whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $moneda }} <span x-text="importe(l).toFixed(2)"></span>
                                </td>

                                <td class="px-3 py-4 text-right align-top">
                                    <button type="button" @click="quitar(i)" :aria-label="`Quitar ${l.nombre}`"
                                        class="rounded-lg p-2 text-gray-400 transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10">
                                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-common.component-card>

        {{-- ------------------------------------------------ el cuadre --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                {{-- El control que no existía: comparar el total del sistema
                     contra el del papel ANTES de guardar. Si no cuadra, algo se
                     tecleó mal y se ve aquí, no el mes que viene. --}}
                <x-common.component-card title="Cuadrar con la factura"
                    desc="Opcional, pero es lo que atrapa un cero de más. Escribe el total que dice el papel.">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="Total según la factura" for="total_factura">
                            <x-form.input id="total_factura" type="number" step="0.01" min="0"
                                x-model.number="totalFactura" placeholder="0.00" />
                        </x-form.campo>

                        <div class="flex items-end pb-1">
                            <template x-if="!totalFactura">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Sin comparar. Puedes guardar igual.
                                </p>
                            </template>
                            <template x-if="totalFactura && Math.abs(diferencia) < 0.005">
                                <p class="text-theme-sm font-medium text-success-700 dark:text-success-500">
                                    Cuadra exacto.
                                </p>
                            </template>
                            <template x-if="totalFactura && Math.abs(diferencia) >= 0.005">
                                <p class="text-theme-sm font-medium text-error-600 dark:text-error-400">
                                    No cuadra: faltan {{ $moneda }}
                                    <span x-text="diferencia.toFixed(2)"></span> para llegar al papel.
                                </p>
                            </template>
                        </div>
                    </div>
                </x-common.component-card>
            </div>

            <x-common.component-card title="Guardar">
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                    <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Total de la compra</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $moneda }} <span x-text="total.toFixed(2)"></span>
                    </p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        <span x-text="lineas.length"></span>
                        <span x-text="lineas.length === 1 ? 'producto' : 'productos'"></span>
                    </p>
                </div>

                <div x-show="avisoSinLineas" x-cloak>
                    <x-ui.alert variant="warning" title="Falta la mercadería"
                        message="Agrega al menos un producto antes de guardar." />
                </div>

                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit" x-bind:disabled="!lineas.length">Registrar compra</x-ui.button>
                    <x-ui.button variant="outline" :href="route('compras.index')">Cancelar</x-ui.button>
                </div>

                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Al guardar, cada línea entra al stock y deja su movimiento en el kardex. La compra no se puede
                    editar después: una corrección es un ajuste de inventario.
                </p>
            </x-common.component-card>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function compraNueva() {
            return {
                busqueda: '',
                resultados: [],
                lineas: [],
                totalFactura: '',
                avisoSinLineas: false,

                async buscar() {
                    const q = this.busqueda.trim();

                    if (q.length < 2) {
                        this.resultados = [];
                        return;
                    }

                    const respuesta = await fetch(`{{ route('compras.productos') }}?q=${encodeURIComponent(q)}`, {
                        headers: { Accept: 'application/json' },
                    });

                    this.resultados = respuesta.ok ? await respuesta.json() : [];
                },

                agregarPrimero() {
                    if (this.resultados.length) this.agregar(this.resultados[0]);
                },

                agregar(p) {
                    /* Un producto ya agregado no se duplica: se lleva el foco a
                       su fila. Dos líneas del mismo producto en la misma factura
                       serían dos entradas al kardex que nadie sabría explicar. */
                    const ya = this.lineas.find((l) => l.id === p.id);

                    if (!ya) {
                        this.lineas.push({
                            ...p,
                            empaques: null,
                            sueltas: null,
                            cantidadSuelta: null,
                            costo: p.costo,
                            costoAnterior: p.costo,
                            costoPor: 'UNIDAD',
                            actualizarCosto: true,
                        });
                    }

                    this.busqueda = '';
                    this.resultados = [];
                    this.avisoSinLineas = false;
                },

                quitar(i) {
                    this.lineas.splice(i, 1);
                },

                /* Las tres cuentas de una línea. Son las mismas que hace el
                   servidor —él las rehace y manda—, pero aquí se ven mientras
                   se teclea, que es cuando sirven. */
                cantidad(l) {
                    const total = l.contenido > 0
                        ? (Number(l.empaques) || 0) * l.contenido + (Number(l.sueltas) || 0)
                        : (Number(l.cantidadSuelta) || 0);

                    return Math.round(total * 1000) / 1000;
                },

                costoUnidad(l) {
                    const costo = Number(l.costo) || 0;

                    return l.costoPor === 'EMPAQUE' && l.contenido > 0
                        ? Math.round((costo / l.contenido) * 100) / 100
                        : costo;
                },

                importe(l) {
                    return Math.round(this.cantidad(l) * this.costoUnidad(l) * 100) / 100;
                },

                get total() {
                    return Math.round(this.lineas.reduce((s, l) => s + this.importe(l), 0) * 100) / 100;
                },

                get diferencia() {
                    return Math.round(((Number(this.totalFactura) || 0) - this.total) * 100) / 100;
                },
            };
        }
    </script>
@endpush
