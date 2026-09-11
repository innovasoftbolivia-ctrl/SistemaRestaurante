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
                        placeholder="Elige el proveedor" :opciones="$proveedores" x-model="proveedorId" required>
                        {{-- Los dados de alta sin salir de aquí se agregan al
                             final de la lista y quedan elegidos. --}}
                        <template x-for="p in proveedoresNuevos" :key="p.id">
                            <option :value="p.id" x-text="p.razon_social"></option>
                        </template>
                    </x-form.select>

                    @puede('productos.gestionar')
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            ¿Es la primera vez que le compras?
                            <button type="button" @click="abrirNuevoProveedor()"
                                class="text-brand-500 hover:text-brand-600 dark:text-brand-400">Registrar proveedor</button>
                        </p>
                    @endpuede
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

                @puede('productos.gestionar')
                    {{-- El producto que llega hoy por primera vez es el caso normal
                         de una compra, no la excepción: mandar a la persona a otra
                         pantalla le costaría todas las líneas que ya tecleó. --}}
                    <p class="-mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                        ¿No está en el catálogo?
                        <button type="button" @click="abrirNuevoProducto()"
                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400">Darlo de alta aquí</button>
                    </p>
                @endpuede

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
                                    <input type="hidden" :name="`lineas[${i}][vence]`" :value="l.vence || ''" />
                                    <input type="hidden" :name="`lineas[${i}][lote]`" :value="l.lote || ''" />

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

                                    {{-- Solo la piden los productos que vencen. Para el
                                         resto la columna ni aparece: una fecha que nadie
                                         va a mirar es una fecha que se llena de cualquier
                                         manera. --}}
                                    <div x-show="l.controlaVencimiento" x-cloak class="mt-2 flex items-start gap-2">
                                        <label class="w-36">
                                            <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Vence el</span>
                                            <x-form.input type="date" x-model="l.vence" />
                                        </label>
                                        <label class="w-28">
                                            <span class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Lote</span>
                                            <x-form.input x-model="l.lote" placeholder="opcional" maxlength="30" />
                                        </label>
                                    </div>
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

        @puede('productos.gestionar')
            {{-- ----------------------------------------- proveedor nuevo --}}
            {{-- Los modales viven DENTRO del formulario de la compra pero sus
                 campos no llevan `name`: se envían por fetch. Si llevaran
                 nombre, viajarían también al registrar la compra y el
                 validador los rechazaría. --}}
            <div x-show="nuevoProveedorAbierto" x-cloak role="dialog" aria-modal="true"
                aria-labelledby="titulo-nuevo-proveedor" @keydown.escape.window="nuevoProveedorAbierto = false"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="nuevoProveedorAbierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="nuevoProveedorAbierto"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-nuevo-proveedor" class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Registrar proveedor
                    </h2>
                    <p class="mb-6 text-theme-xs text-gray-500 dark:text-gray-400">
                        Se guarda y queda elegido para esta compra, sin perder las líneas que ya cargaste.
                    </p>

                    <div class="space-y-5">
                        <p x-show="nuevoProveedorError" x-cloak
                            class="rounded-xl border border-error-300 px-4 py-3 text-theme-sm text-error-600 dark:border-error-700 dark:text-error-400"
                            x-text="nuevoProveedorError"></p>

                        <div>
                            <label for="np_razon" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Razón social<span class="text-error-600 dark:text-error-400">*</span>
                            </label>
                            <x-form.input id="np_razon" x-model="nuevoProveedor.razon_social"
                                placeholder="Distribuidora del Norte S.A.C." maxlength="120" />
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="np_nit" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">NIT</label>
                                <x-form.input id="np_nit" x-model="nuevoProveedor.documento"
                                    placeholder="1023456789" maxlength="20" />
                            </div>
                            <div>
                                <label for="np_tel" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Teléfono</label>
                                <x-form.input id="np_tel" x-model="nuevoProveedor.telefono" maxlength="20" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm"
                                @click="nuevoProveedorAbierto = false">Cancelar</x-ui.button>
                            <x-ui.button type="button" size="sm" @click="guardarProveedor()"
                                x-bind:disabled="guardandoProveedor">Guardar proveedor</x-ui.button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------ producto nuevo --}}
            <div x-show="nuevoProductoAbierto" x-cloak role="dialog" aria-modal="true"
                aria-labelledby="titulo-nuevo-producto" @keydown.escape.window="nuevoProductoAbierto = false"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="nuevoProductoAbierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="nuevoProductoAbierto"
                    class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-nuevo-producto" class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Dar de alta un producto
                    </h2>
                    <p class="mb-6 text-theme-xs text-gray-500 dark:text-gray-400">
                        Lo imprescindible para poder comprarlo y venderlo. Entra al catálogo con stock cero: las
                        unidades las pone esta misma compra. El código interno lo asigna el sistema, y la foto, el
                        código de barras y el resto se completan después desde el catálogo.
                    </p>

                    <div class="space-y-5">
                        <p x-show="nuevoProductoError" x-cloak
                            class="rounded-xl border border-error-300 px-4 py-3 text-theme-sm text-error-600 dark:border-error-700 dark:text-error-400"
                            x-text="nuevoProductoError"></p>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="nprod_nombre" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Nombre<span class="text-error-600 dark:text-error-400">*</span>
                                </label>
                                <x-form.input id="nprod_nombre" x-model="nuevoProducto.nombre"
                                    placeholder="Arroz extra 1 kg" maxlength="120" />
                            </div>

                            <div>
                                <label for="nprod_categoria" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Categoría<span class="text-error-600 dark:text-error-400">*</span>
                                </label>
                                <x-form.select id="nprod_categoria" x-model="nuevoProducto.categoria_id"
                                    placeholder="Elige una categoría" :opciones="$categorias" />
                            </div>
                        </div>

                        {{-- Las dos preguntas de siempre, en el mismo orden que en
                             el alta completa: cómo llega y cómo sale. --}}
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="nprod_empaque" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    ¿Cómo te lo entrega el proveedor?
                                </label>
                                <x-form.select id="nprod_empaque" x-model="nuevoProducto.empaque"
                                    :opciones="array_merge(['__suelto' => 'Suelto — igual que lo vendo'], array_combine(array_keys($empaques), array_keys($empaques)))" />
                            </div>

                            <div>
                                <label for="nprod_unidad" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    ¿Cómo lo vendes?<span class="text-error-600 dark:text-error-400">*</span>
                                </label>
                                <x-form.select id="nprod_unidad" x-model="nuevoProducto.unidad_medida_id"
                                    placeholder="Elige una unidad" :opciones="$unidades" />
                            </div>

                            <div x-show="nuevoProducto.empaque !== '__suelto'" x-cloak>
                                <label for="nprod_contenido" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    ¿Cuánto trae cada uno?
                                </label>
                                <x-form.input id="nprod_contenido" type="number" step="0.001" min="0"
                                    x-model.number="nuevoProducto.contenido" placeholder="24" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                            <div>
                                <label for="nprod_compra" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Precio de compra<span class="text-error-600 dark:text-error-400">*</span>
                                </label>
                                <x-form.input id="nprod_compra" type="number" step="0.01" min="0"
                                    x-model.number="nuevoProducto.precio_compra" />
                                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">por unidad de venta</p>
                            </div>

                            <div>
                                <label for="nprod_venta" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Precio de venta<span class="text-error-600 dark:text-error-400">*</span>
                                </label>
                                <x-form.input id="nprod_venta" type="number" step="0.01" min="0"
                                    x-model.number="nuevoProducto.precio_venta" />
                            </div>

                            <div>
                                <label for="nprod_minimo" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Stock mínimo
                                </label>
                                <x-form.input id="nprod_minimo" type="number" step="0.001" min="0"
                                    x-model.number="nuevoProducto.stock_minimo" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm"
                                @click="nuevoProductoAbierto = false">Cancelar</x-ui.button>
                            <x-ui.button type="button" size="sm" @click="guardarProducto()"
                                x-bind:disabled="guardandoProducto">Guardar y agregar a la compra</x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        @endpuede
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

                proveedorId: '{{ old('proveedor_id') }}',
                proveedoresNuevos: [],
                nuevoProveedorAbierto: false,
                nuevoProveedorError: '',
                guardandoProveedor: false,
                nuevoProveedor: { razon_social: '', documento: '', telefono: '' },

                nuevoProductoAbierto: false,
                nuevoProductoError: '',
                guardandoProducto: false,
                nuevoProducto: {},

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
                            vence: '',
                            lote: '',
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

                /* ---------------------------------------------- alta rápida
                   Las dos altas van por fetch y no por navegación: quien está
                   cargando una factura de treinta líneas no puede permitirse
                   salir de la pantalla y volver. Es el mismo recurso y la misma
                   validación del módulo correspondiente — solo cambia que la
                   respuesta vuelve en JSON. */
                async enviar(url, datos) {
                    const respuesta = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(datos),
                    });

                    const cuerpo = await respuesta.json();

                    if (!respuesta.ok) {
                        const primero = cuerpo.errors ? Object.values(cuerpo.errors)[0]?.[0] : null;
                        throw new Error(primero ?? cuerpo.message ?? 'No se pudo guardar.');
                    }

                    return cuerpo;
                },

                abrirNuevoProveedor() {
                    this.nuevoProveedor = { razon_social: '', documento: '', telefono: '' };
                    this.nuevoProveedorError = '';
                    this.nuevoProveedorAbierto = true;
                },

                async guardarProveedor() {
                    this.nuevoProveedorError = '';
                    this.guardandoProveedor = true;

                    try {
                        const p = await this.enviar('{{ route('proveedores.store') }}', this.nuevoProveedor);

                        this.proveedoresNuevos.push(p);
                        this.proveedorId = String(p.id);
                        this.nuevoProveedorAbierto = false;
                    } catch (e) {
                        this.nuevoProveedorError = e.message;
                    } finally {
                        this.guardandoProveedor = false;
                    }
                },

                abrirNuevoProducto() {
                    this.nuevoProducto = {
                        /* El nombre arranca con lo que ya se había tecleado en el
                           buscador: se llega aquí justo después de no encontrarlo. */
                        nombre: this.busqueda.trim(),
                        categoria_id: '',
                        unidad_medida_id: '',
                        empaque: '__suelto',
                        contenido: null,
                        precio_compra: null,
                        precio_venta: null,
                        stock_minimo: 0,
                    };
                    this.nuevoProductoError = '';
                    this.nuevoProductoAbierto = true;
                },

                async guardarProducto() {
                    this.nuevoProductoError = '';
                    this.guardandoProducto = true;

                    const n = this.nuevoProducto;
                    const enEmpaque = n.empaque !== '__suelto';

                    try {
                        /* Sin `stock_inicial`: las unidades las pone la línea de
                           esta misma compra. Cargarlo aquí las contaría dos veces. */
                        const p = await this.enviar('{{ route('productos.store') }}', {
                            nombre: n.nombre,
                            categoria_id: n.categoria_id,
                            unidad_medida_id: n.unidad_medida_id,
                            viene_en_empaque: enEmpaque ? 1 : 0,
                            nombre_empaque: enEmpaque ? n.empaque : null,
                            contenido_empaque: enEmpaque ? n.contenido : null,
                            precio_compra: n.precio_compra,
                            precio_venta: n.precio_venta,
                            stock_minimo: n.stock_minimo ?? 0,
                            stock_inicial: 0,
                            activo: 1,
                        });

                        this.agregar(p);
                        this.nuevoProductoAbierto = false;
                    } catch (e) {
                        this.nuevoProductoError = e.message;
                    } finally {
                        this.guardandoProducto = false;
                    }
                },
            };
        }
    </script>
@endpush
