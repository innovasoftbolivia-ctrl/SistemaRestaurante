@extends('layouts.app')

@php
    use App\Support\Config;

    // Las líneas se arman en el navegador, así que sus campos no pueden usar
    // los componentes de formulario (llevan nombre fijo). Mismo aspecto.
    $claseCampo = 'dark:bg-dark-900 shadow-theme-xs h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 '
        .'text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 '
        .'focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 '
        .'dark:focus:border-brand-800';

    // Si la validación rebotó, las líneas vuelven como se habían escrito.
    $lineasIniciales = collect(old('lineas', []))
        ->map(fn ($l) => [
            'producto_id' => (string) ($l['producto_id'] ?? ''),
            'empaques' => (string) ($l['empaques'] ?? ''),
            'sueltas' => (string) ($l['sueltas'] ?? ''),
            'costo' => (string) ($l['costo'] ?? ''),
            'costo_por' => ($l['costo_por'] ?? 'unidad') === 'empaque' ? 'empaque' : 'unidad',
        ])
        ->values();
@endphp

@section('content')
    <div class="space-y-6">
        @if ($productos->isEmpty())
            <x-ui.alert variant="warning" title="Todavía no hay nada que lleve inventario">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Solo se compra lo que se vende hecho, como las bebidas embotelladas. Abre su ficha en el
                    <b>Menú</b>, marca <b>«Lleva inventario»</b> y, si viene en caja o paquete, indica cuántas unidades
                    trae. Después vuelve aquí para registrar la compra.
                </p>
                <a href="{{ route('productos.index') }}"
                    class="mt-3 inline-block text-sm font-medium text-brand-600 underline hover:text-brand-700 dark:text-brand-400">
                    Ir al Menú
                </a>
            </x-ui.alert>
        @endif

        @if ($proveedores->isEmpty())
            <x-ui.alert variant="warning" title="No hay proveedores activos">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Cada compra se registra a nombre de un proveedor. Registra primero a quién le compras.
                </p>
                <a href="{{ route('proveedores.index') }}"
                    class="mt-3 inline-block text-sm font-medium text-brand-600 underline hover:text-brand-700 dark:text-brand-400">
                    Registrar un proveedor
                </a>
            </x-ui.alert>
        @endif

        @if ($productos->isNotEmpty() && $proveedores->isNotEmpty())
            <form method="POST" action="{{ route('compras.store') }}" class="space-y-6"
                x-data="{
                    productos: @js($productos),
                    lineas: [],
                    moneda: @js(Config::moneda()),
                    siguiente: 0,

                    init() {
                        const iniciales = @js($lineasIniciales);
                        (iniciales.length ? iniciales : [null]).forEach(l => this.agregar(l));
                    },

                    agregar(datos = null) {
                        this.lineas.push(Object.assign(
                            { clave: this.siguiente++, producto_id: '', empaques: '', sueltas: '', costo: '', costo_por: 'unidad' },
                            datos ?? {}
                        ));
                    },
                    quitar(i) {
                        this.lineas.splice(i, 1);
                        if (! this.lineas.length) this.agregar();
                    },

                    producto(l) {
                        return this.productos.find(p => String(p.id) === String(l.producto_id)) ?? null;
                    },
                    conEmpaque(l) {
                        const p = this.producto(l);
                        return !! (p && p.contenido > 1);
                    },
                    usado(id, l) {
                        return this.lineas.some(o => o !== l && String(o.producto_id) === String(id));
                    },

                    /* Al elegir el producto se propone su último costo, por caja si viene en caja. */
                    elegido(l) {
                        const p = this.producto(l);
                        l.empaques = '';
                        l.sueltas = '';
                        l.costo_por = this.conEmpaque(l) ? 'empaque' : 'unidad';
                        l.costo = p && p.costo !== null
                            ? String(l.costo_por === 'empaque' ? this.r2(p.costo * p.contenido) : p.costo)
                            : '';
                    },

                    /* Cambiar entre caja y unidad convierte el costo ya escrito. */
                    costoPor(l, por) {
                        if (l.costo_por === por) return;
                        const p = this.producto(l);
                        if (l.costo !== '' && p) {
                            l.costo = String(por === 'empaque' ? this.r2(l.costo * p.contenido) : this.r2(l.costo / p.contenido));
                        }
                        l.costo_por = por;
                    },

                    unidades(l) {
                        const p = this.producto(l);
                        if (! p) return 0;
                        const cajas = this.conEmpaque(l) ? (parseInt(l.empaques) || 0) : 0;
                        return Math.round((cajas * p.contenido + (parseFloat(l.sueltas) || 0)) * 1000) / 1000;
                    },
                    costoUnitario(l) {
                        const costo = parseFloat(l.costo) || 0;
                        return this.conEmpaque(l) && l.costo_por === 'empaque'
                            ? this.r2(costo / this.producto(l).contenido)
                            : costo;
                    },
                    subtotal(l) {
                        return this.r2(this.unidades(l) * this.costoUnitario(l));
                    },
                    get total() {
                        return this.r2(this.lineas.reduce((s, l) => s + this.subtotal(l), 0));
                    },

                    r2(n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; },
                    dinero(n) {
                        return this.moneda + ' ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                    cantidad(n) { return String(Math.round(n * 1000) / 1000); },
                    empaqueDe(l) {
                        const p = this.producto(l);
                        return p ? (p.empaque ?? 'Caja') : 'Caja';
                    },
                }">
                @csrf
                @unEnvio

                <x-common.component-card title="Factura del proveedor"
                    desc="Lo que dice el papel que trajo el proveedor. El número de factura es opcional.">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="Proveedor" for="proveedor_id" name="proveedor_id" required>
                            <x-form.select id="proveedor_id" name="proveedor_id" placeholder="Elige el proveedor"
                                :opciones="$proveedores" required />
                        </x-form.campo>

                        <x-form.campo label="N.º de factura o nota" for="documento_externo" name="documento_externo">
                            <x-form.input id="documento_externo" name="documento_externo" placeholder="001234"
                                maxlength="30" />
                        </x-form.campo>

                        <div class="sm:col-span-2">
                            <x-form.campo label="Observación" for="observacion" name="observacion">
                                <x-form.textarea id="observacion" name="observacion" rows="2" maxlength="255"
                                    placeholder="Opcional: algo que convenga recordar de esta compra" />
                            </x-form.campo>
                        </div>
                    </div>
                </x-common.component-card>

                <x-common.component-card title="Productos"
                    desc="Si el producto viene en caja, escribe cuántas cajas y cuántas sueltas llegaron, y si el costo que dice la factura es por caja o por unidad. El sistema lo pasa todo a unidades.">
                    <div class="space-y-4">
                        <template x-for="(linea, i) in lineas" :key="linea.clave">
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-end">
                                    {{-- Producto --}}
                                    <div class="lg:col-span-4">
                                        <label :for="`linea-${linea.clave}-producto`"
                                            class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                            Producto<span class="text-error-600 dark:text-error-400">*</span>
                                        </label>
                                        <select :id="`linea-${linea.clave}-producto`" :name="`lineas[${i}][producto_id]`"
                                            x-model="linea.producto_id" @change="elegido(linea)" required
                                            class="{{ $claseCampo }} appearance-none pr-8">
                                            <option value="">Elige el producto</option>
                                            @foreach ($productos as $p)
                                                <option value="{{ $p['id'] }}" :disabled="usado(@js((string) $p['id']), linea)">
                                                    {{ $p['nombre'] }}{{ $p['empaque_visible'] ? ' — '.$p['empaque_visible'] : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-show="producto(linea)"
                                            x-text="producto(linea) ? `${producto(linea).categoria ?? ''}${producto(linea).categoria ? ' · ' : ''}En bodega: ${producto(linea).stock}` : ''"></p>
                                    </div>

                                    {{-- Cantidad --}}
                                    <template x-if="conEmpaque(linea)">
                                        <div class="grid grid-cols-2 gap-3 lg:col-span-3">
                                            <div>
                                                <label :for="`linea-${linea.clave}-empaques`"
                                                    class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400"
                                                    x-text="empaqueDe(linea) + 's'"></label>
                                                <input type="number" min="0" step="1" inputmode="numeric"
                                                    :id="`linea-${linea.clave}-empaques`" :name="`lineas[${i}][empaques]`"
                                                    x-model="linea.empaques" placeholder="0" class="{{ $claseCampo }}" />
                                            </div>
                                            <div>
                                                <label :for="`linea-${linea.clave}-sueltas`"
                                                    class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Sueltas</label>
                                                <input type="number" min="0" step="any" inputmode="decimal"
                                                    :id="`linea-${linea.clave}-sueltas`" :name="`lineas[${i}][sueltas]`"
                                                    x-model="linea.sueltas" placeholder="0" class="{{ $claseCampo }}" />
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="! conEmpaque(linea)">
                                        <div class="lg:col-span-3">
                                            <label :for="`linea-${linea.clave}-sueltas`"
                                                class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Unidades</label>
                                            <input type="number" min="0" step="any" inputmode="decimal"
                                                :id="`linea-${linea.clave}-sueltas`" :name="`lineas[${i}][sueltas]`"
                                                x-model="linea.sueltas" placeholder="0" class="{{ $claseCampo }}" />
                                        </div>
                                    </template>

                                    {{-- Costo --}}
                                    <div class="lg:col-span-4">
                                        <div class="mb-1.5 flex items-center justify-between gap-2">
                                            <label :for="`linea-${linea.clave}-costo`"
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-400">
                                                Costo<span class="text-error-600 dark:text-error-400">*</span>
                                            </label>
                                            <div x-show="conEmpaque(linea)" role="group" aria-label="El costo es por"
                                                class="inline-flex rounded-lg bg-gray-100 p-0.5 text-theme-xs dark:bg-white/[0.05]">
                                                <button type="button" @click="costoPor(linea, 'empaque')"
                                                    :aria-pressed="linea.costo_por === 'empaque'"
                                                    :class="linea.costo_por === 'empaque' ? 'bg-white text-gray-800 shadow-theme-xs dark:bg-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400'"
                                                    class="rounded-md px-2.5 py-1 font-medium transition"
                                                    x-text="'por ' + empaqueDe(linea).toLowerCase()"></button>
                                                <button type="button" @click="costoPor(linea, 'unidad')"
                                                    :aria-pressed="linea.costo_por === 'unidad'"
                                                    :class="linea.costo_por === 'unidad' ? 'bg-white text-gray-800 shadow-theme-xs dark:bg-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400'"
                                                    class="rounded-md px-2.5 py-1 font-medium transition">por unidad</button>
                                            </div>
                                        </div>
                                        <input type="hidden" :name="`lineas[${i}][costo_por]`"
                                            :value="conEmpaque(linea) ? linea.costo_por : 'unidad'" />
                                        <input type="number" min="0" step="0.01" inputmode="decimal" required
                                            :id="`linea-${linea.clave}-costo`" :name="`lineas[${i}][costo]`"
                                            x-model="linea.costo" placeholder="0.00" class="{{ $claseCampo }}" />
                                    </div>

                                    <div class="flex justify-end lg:col-span-1">
                                        <button type="button" @click="quitar(i)" title="Quitar la línea"
                                            aria-label="Quitar la línea"
                                            class="rounded-lg p-2.5 text-gray-500 transition hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Lo que entra al stock, calculado al vuelo --}}
                                <div x-show="producto(linea)"
                                    class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-gray-100 pt-3 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                    <span>Entran <b class="text-gray-800 dark:text-white/90" x-text="cantidad(unidades(linea))"></b> u.</span>
                                    <span>Costo por unidad <b class="text-gray-800 dark:text-white/90" x-text="dinero(costoUnitario(linea))"></b></span>
                                    <span class="ml-auto">Subtotal <b class="text-gray-800 dark:text-white/90" x-text="dinero(subtotal(linea))"></b></span>
                                </div>
                            </div>
                        </template>

                        <x-ui.button type="button" variant="outline" size="sm" @click="agregar()">
                            Agregar otro producto
                        </x-ui.button>
                    </div>
                </x-common.component-card>

                <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Total de la factura</p>
                        <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90" x-text="dinero(total)">
                            {{ Config::importe(0) }}
                        </p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            Al registrarla sube el stock y cada producto queda con este costo como su último costo.
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <x-ui.button variant="outline" size="sm" :href="route('compras.index')">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Registrar compra</x-ui.button>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endsection
