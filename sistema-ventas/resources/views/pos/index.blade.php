@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    @unless ($sesion)
        {{-- Sin caja abierta no se puede cobrar: no habría dónde imputar el dinero. --}}
        <div class="mx-auto max-w-xl">
            <x-common.component-card title="No tienes una caja abierta"
                desc="Cada venta se imputa a un turno de caja. Abre el tuyo para empezar a cobrar.">
                <x-ui.button :href="route('caja.index')" class="w-full">Ir a caja</x-ui.button>
            </x-common.component-card>
        </div>
    @else
        {{-- `pb-28` deja sitio en móvil a la barra flotante del carrito. --}}
        <div x-data="mostrador()" x-init="cargar(); $nextTick(() => $refs.buscador?.focus())"
        @keydown.window="atajos($event)"
            class="grid grid-cols-1 gap-6 pb-28 xl:grid-cols-5 xl:pb-0">

            {{-- Catálogo --}}
            <div class="space-y-4 xl:col-span-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <div class="relative flex-1">
                            <span class="absolute top-1/2 left-4 -translate-y-1/2 text-gray-400">
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                                        fill="currentColor" />
                                </svg>
                            </span>
                            <input x-ref="buscador" x-model="q" @input.debounce.250ms="cargar()"
                                @keydown.enter.prevent="porCodigo()" type="text"
                                placeholder="Código de barras, código interno o nombre — luego Enter"
                                class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-12 w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-4 pl-12 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                    </div>

                    {{-- Un código escaneado que no está en el catálogo se dice.
                         Antes se agregaba el primero de la pantalla y el cajero
                         cobraba otra cosa sin enterarse. --}}
                    <div x-show="codigoNoEncontrado" x-cloak
                        class="mt-3 flex items-center gap-2 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
                        <span>No hay ningún producto con el código
                            <strong x-text="codigoNoEncontrado"></strong>. Revisá que esté cargado en el catálogo.</span>
                        <button type="button" @click="codigoNoEncontrado = ''"
                            class="ml-auto text-theme-xs underline">Cerrar</button>
                    </div>

                    {{-- Los atajos dibujados como teclas: se leen de un vistazo,
                         que es lo que hace falta en un mostrador. --}}
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-theme-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1.5"><kbd class="tecla">Enter</kbd> agrega el primero</span>
                        <span class="flex items-center gap-1.5"><kbd class="tecla">F2</kbd> vuelve al buscador</span>
                        <span class="flex items-center gap-1.5"><kbd class="tecla">F4</kbd> cobra</span>
                    </div>

                    {{-- Fichas en vez de desplegable: se ve de un golpe cuántos
                         productos hay en cada categoría y se elige de un toque.
                         El scroll horizontal las salva en pantallas estrechas. --}}
                    <div class="-mx-1 mt-3 flex gap-2 overflow-x-auto overscroll-x-contain px-1 pb-1">
                        <button type="button" @click="categoria = ''; cargar()"
                            :class="categoria === ''
                                ? 'bg-brand-500 text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.06] dark:text-gray-400 dark:hover:bg-white/10'"
                            class="flex-none min-h-11 rounded-full px-4 py-3 text-theme-xs font-medium transition">
                            Todas
                        </button>

                        @foreach ($categorias as $categoria)
                            <button type="button" @click="categoria = '{{ $categoria->id }}'; cargar()"
                                :class="categoria === '{{ $categoria->id }}'
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.06] dark:text-gray-400 dark:hover:bg-white/10'"
                                class="flex-none min-h-11 rounded-full px-4 py-3 text-theme-xs font-medium transition">
                                {{ $categoria->nombre }}
                                <span class="opacity-60">{{ $categoria->productos_count }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Dos columnas en el teléfono, tres en tableta y cuatro de ahí
                     en adelante. En `xl` el carrito se lleva dos quintos del
                     ancho, así que cuatro columnas siguen siendo cómodas. --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="p in productos" :key="p.id">
                        <button type="button" @click="agregar(p)" :disabled="p.stock <= 0"
                            class="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white text-left transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-theme-md focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:opacity-45 disabled:hover:translate-y-0 disabled:hover:shadow-none dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">

                            {{-- La foto manda en la tarjeta: ocupa el ancho completo
                                 en 4:3, sin marco.

                                 `object-scale-down` y NO `object-cover`: el recuadro
                                 mide siempre lo mismo, pero la foto entra entera.
                                 Recortarla dejaría fuera parte del envase, que es
                                 justo por lo que el cajero la reconoce. El fondo
                                 claro hace que el aire alrededor se vea a propósito. --}}
                            <span class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden bg-white p-2 dark:bg-white/[0.06]">
                                <template x-if="p.imagen">
                                    <img :src="p.imagen" :alt="p.nombre" loading="lazy"
                                        class="max-h-full max-w-full object-scale-down" />
                                </template>

                                {{-- Sin foto todavía: icono neutro. La inicial no sirve
                                     de por sí para distinguir productos —más de la
                                     mitad del catálogo empieza por la misma letra—,
                                     así que aquí solo se avisa «sin foto todavía»;
                                     lo que sí distingue es el código y la categoría,
                                     debajo del nombre. --}}
                                <template x-if="!p.imagen">
                                    <span class="flex h-full w-full items-center justify-center text-gray-300 dark:text-white/15">
                                        <svg aria-hidden="true" width="36" height="36" viewBox="0 0 24 24" fill="none">
                                            <path d="M3.75 7.25 12 3.5l8.25 3.75-8.25 3.75L3.75 7.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                            <path d="M3.75 12 12 15.75 20.25 12M3.75 16.75 12 20.5l8.25-3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                </template>

                                {{-- Lo que ya va en el carrito, con su cantidad: evita
                                     agregar dos veces el mismo producto sin notarlo. --}}
                                <template x-if="enCarrito(p.id)">
                                    <span class="absolute right-2 top-2 flex h-7 min-w-7 items-center justify-center rounded-full bg-success-600 px-2 text-theme-xs font-bold text-white shadow-theme-sm"
                                        x-text="cantidadTexto(enCarrito(p.id))"></span>
                                </template>

                                <template x-if="p.stock <= 0">
                                    <span class="absolute inset-x-0 bottom-0 bg-error-600/90 py-1 text-center text-theme-xs font-semibold text-white">
                                        Agotado
                                    </span>
                                </template>
                            </span>

                            <span class="block p-3">
                                <span class="mb-1 line-clamp-2 min-h-9 text-theme-sm font-medium leading-snug text-gray-800 dark:text-white/90"
                                    x-text="p.nombre"></span>

                                {{-- Código interno y categoría: lo que de verdad
                                     distingue dos productos de nombre parecido, y lo
                                     que el cajero teclea cuando el lector no lee. --}}
                                <span class="mb-1.5 flex items-center gap-1.5 overflow-hidden">
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400" x-text="p.codigo"></span>
                                    <span x-show="nombreCategoria(p.categoria_id)"
                                        class="truncate rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="tonoCategoria(p.categoria_id)"
                                        x-text="nombreCategoria(p.categoria_id)"></span>
                                </span>

                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="whitespace-nowrap text-base font-bold text-brand-600 dark:text-brand-400"
                                        x-text="'{{ $moneda }} ' + p.precio_estante.toFixed(2)"></span>
                                    <span class="text-theme-xs whitespace-nowrap"
                                        :class="p.stock > 0 && p.stock <= 5 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400'"
                                        x-text="p.stock <= 0 ? '' : (cantidadTexto(p.stock) + ' ' + p.unidad)"></span>
                                </span>

                                {{-- Cuántas cajas quedan, para el que mira el estante
                                     y quiere saber si ya hay que pedir. --}}
                                <span x-show="p.stock > 0 && p.desglose" x-cloak
                                    class="block truncate text-[10px] text-gray-400 dark:text-gray-500"
                                    x-text="p.desglose"></span>
                            </span>
                        </button>
                    </template>

                    <p x-show="!productos.length" class="col-span-full py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                        No hay productos que coincidan con la búsqueda.
                    </p>
                </div>
            </div>

            {{-- Carrito y cobro --}}
            <div class="xl:col-span-2">
                {{-- En escritorio el carrito acompaña el desplazamiento; en móvil
                     va después de la cuadrícula y se llega a él con la barra
                     flotante de abajo.

                     `overflow-hidden` y NO `overflow-y-auto`: el panel entero no
                     se desplaza. Si lo hace, el pie —con el total y el botón de
                     cobrar— se va por debajo del borde de la pantalla y el cajero
                     tiene que rodar la rueda para cobrar. Aquí solo se desplaza
                     la zona de dentro que lleva `overflow-y-auto`, y el pie queda
                     anclado siempre a la vista.

                     El límite de altura NO usa el mismo `8rem` que el desplazamiento
                     (`top-24`): antes de hacer scroll el panel arranca más abajo —a
                     la altura de la cabecera y el título de la página, unos `10rem`—
                     y con el número de `top-24` el pie (con el botón de cobrar)
                     quedaba unos 25 px fuera de la pantalla nada más cargar. --}}
                <form method="POST" action="{{ route('pos.store') }}" @submit.prevent="confirmarYEnviar($event)" x-ref="carrito"
                    class="flex flex-col rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] xl:sticky xl:top-24 xl:max-h-[calc(100vh-10rem)] xl:overflow-hidden">
                    @csrf
                    <div x-ref="campos"></div>

                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Carrito</h2>

                            <div class="flex items-center gap-3">
                                <span x-show="carrito.length"
                                    class="rounded-full bg-brand-50 px-3 py-1 text-theme-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-400"
                                    x-text="cantidadTexto(articulos) + (articulos == 1 ? ' artículo' : ' artículos')"></span>
                                <button type="button" x-show="carrito.length" @click="carrito = []"
                                    class="-my-2 flex min-h-11 items-center rounded-lg px-2 text-theme-xs font-medium text-error-600 transition hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">Vaciar</button>
                            </div>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $sesion->caja?->nombre }} · turno abierto por {{ $sesion->usuarioApertura?->usuario ?? auth()->user()->usuario }}
                        </p>
                    </div>

                    {{--
                        Todo lo que va ENTRE la cabecera y el botón de cobrar vive
                        aquí dentro: el carrito, el cliente, los totales y la forma
                        de pago. Es la única zona con `overflow-y-auto` del panel;
                        si el contenido no cabe —muchas líneas, o el formulario de
                        «efectivo recibido» desplegado— se desplaza aquí y NO
                        empuja al botón fuera de la pantalla, porque el botón vive
                        fuera de este contenedor, anclado como pie del panel.

                        El alto mínimo en la lista de artículos no es decorativo:
                        con `flex-1` y sin él, en una pantalla baja la lista se
                        encoge hasta CERO y los artículos —con sus botones de
                        cantidad— desaparecen sin previo aviso.
                    --}}
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <div class="min-h-40 divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="(l, i) in carrito" :key="l.producto_id">
                            <div class="px-5 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                            x-text="l.nombre"></p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400"
                                            x-text="'{{ $moneda }} ' + l.precio_estante.toFixed(2) + ' × ' + cantidadTexto(l.cantidad) + ' ' + l.unidad"></p>
                                    </div>
                                    <button type="button" @click="quitar(i)" :aria-label="`Quitar ${l.nombre} del carrito`"
                                        class="-mr-2 -mt-2 flex h-11 w-11 flex-none items-center justify-center rounded-lg text-gray-400 transition hover:bg-error-50 hover:text-error-500 dark:hover:bg-error-500/10">
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none">
                                            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </div>

                                {{-- Hay una fila de estas por producto en el carrito: sin nombre
                                     propio, un lector de pantalla no dice de cuál está hablando. --}}
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="sumar(i, -1)" :aria-label="`Quitar una unidad de ${l.nombre}`"
                                            class="h-11 w-11 text-base rounded-lg border border-gray-200 text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">−</button>
                                        <input type="number" inputmode="decimal" :step="l.decimal ? '0.001' : '1'" min="0"
                                            x-model.number="l.cantidad" @change="normalizar(i)"
                                            :aria-label="`Cantidad de ${l.nombre}`"
                                            class="dark:bg-dark-900 h-11 w-20 rounded-lg border border-gray-300 bg-transparent px-2 text-center text-sm text-gray-800 focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                        <button type="button" @click="sumar(i, 1)" :aria-label="`Agregar una unidad de ${l.nombre}`"
                                            class="h-11 w-11 text-base rounded-lg border border-gray-200 text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">+</button>
                                    </div>
                                    <span class="text-theme-sm font-semibold text-gray-800 dark:text-white/90"
                                        x-text="'{{ $moneda }} ' + (l.precio_estante * l.cantidad).toFixed(2)"></span>
                                </div>

                                <p x-show="l.cantidad > l.stock" class="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                                    Solo quedan <span x-text="cantidadTexto(l.stock)"></span> <span x-text="l.unidad"></span>.
                                </p>
                            </div>
                        </template>

                        {{-- El carrito vacío es el estado más frecuente al empezar
                             una venta: dice qué hacer, en vez de una línea de
                             texto gris en medio de un hueco. --}}
                        <div x-show="!carrito.length" class="flex min-h-40 flex-col items-center justify-center gap-3 px-6 py-10 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10 dark:text-brand-400">
                                <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none">
                                    <path d="M4 7V5a1 1 0 0 1 1-1h2M4 17v2a1 1 0 0 0 1 1h2M20 7V5a1 1 0 0 0-1-1h-2M20 17v2a1 1 0 0 1-1 1h-2M7 8v8M10 8v8M13 8v8M17 8v8"
                                        stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                </svg>
                            </span>
                            <p class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Escanea el primer producto</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                o búscalo por nombre y pulsa <kbd class="tecla">Enter</kbd>
                            </p>
                        </div>
                    </div>

                    {{-- Cliente --}}
                    {{-- (dentro de la zona con scroll: sigue justo después del carrito) --}}
                    <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        <label for="cliente" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                            Cliente
                        </label>
                        <select id="cliente" x-model.number="clienteId"
                            class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="">{{ $clienteGenerico }} (venta al paso — recibo)</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c['id'] }}" data-juridica="{{ $c['juridica'] ? '1' : '0' }}">
                                    {{ $c['etiqueta'] }}{{ $c['juridica'] ? ' — factura' : '' }}
                                </option>
                            @endforeach
                            {{-- Los que se registran sin salir del mostrador, en esta misma venta. --}}
                            <template x-for="c in clientesNuevos" :key="c.id">
                                <option :value="c.id" x-text="c.etiqueta + (c.juridica ? ' — factura' : '')"></option>
                            </template>
                        </select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Persona jurídica recibe <b>factura</b>; el resto, <b>recibo</b>.
                            <button type="button" @click="abrirNuevoCliente()"
                                class="-my-2 px-1 py-2 font-medium text-brand-500 dark:text-brand-400 hover:text-brand-600">Registrar cliente</button>
                        </p>
                    </div>

                    {{-- Totales --}}
                    {{-- `aria-live="polite"`: el total cambia solo, al añadir un
                         artículo o teclear un descuento. Sin esto, quien usa
                         lector de pantalla cobra sin haber oído el importe. --}}
                    <div class="space-y-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800"
                        aria-live="polite" aria-atomic="true">
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Subtotal (base)</span>
                            <span x-text="'{{ $moneda }} ' + subtotal.toFixed(2)"></span>
                        </div>

                        {{-- El descuento se teclea en {{ $moneda }} o en %, pero a la venta
                             y al ticket siempre llega el monto: el porcentaje solo es
                             una forma de calcularlo. --}}
                        <div class="flex items-center justify-between gap-3">
                            <label for="descuento" class="text-theme-sm text-gray-500 dark:text-gray-400">Descuento</label>
                            <div class="flex items-center gap-2">
                                <div role="group" aria-label="Descontar en" class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.05]">
                                    <button type="button" @click="descuentoModo = 'monto'" :aria-pressed="descuentoModo === 'monto'"
                                        :class="descuentoModo === 'monto' ? 'bg-white text-gray-800 shadow-theme-xs dark:bg-gray-800 dark:text-white/90' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                        class="h-10 min-w-10 rounded-md px-2 text-theme-xs font-medium transition">{{ $moneda }}</button>
                                    <button type="button" @click="descuentoModo = 'porcentaje'" :aria-pressed="descuentoModo === 'porcentaje'"
                                        :class="descuentoModo === 'porcentaje' ? 'bg-white text-gray-800 shadow-theme-xs dark:bg-gray-800 dark:text-white/90' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                        class="h-10 min-w-10 rounded-md px-2 text-theme-xs font-medium transition">%</button>
                                </div>
                                <input id="descuento" type="number" inputmode="decimal" min="0" x-model.number="descuento"
                                    :step="descuentoModo === 'porcentaje' ? '0.5' : '0.01'"
                                    :max="descuentoModo === 'porcentaje' ? 100 : null"
                                    :aria-label="descuentoModo === 'porcentaje' ? 'Descuento en porcentaje' : 'Descuento en {{ $moneda }}'"
                                    class="dark:bg-dark-900 h-11 w-24 rounded-lg border border-gray-300 bg-transparent px-2 text-right text-sm text-gray-800 focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ([5, 10] as $rapido)
                                    <button type="button" @click="descontarPorcentaje({{ $rapido }})"
                                        :aria-pressed="descuentoModo === 'porcentaje' && Number(descuento) === {{ $rapido }}"
                                        :class="descuentoModo === 'porcentaje' && Number(descuento) === {{ $rapido }}
                                            ? 'bg-brand-500 text-white'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10'"
                                        class="min-h-10 rounded-lg px-3 text-theme-xs font-medium transition">{{ $rapido }} %</button>
                                @endforeach
                                <button type="button" x-show="descuentoValido > 0" @click="descuento = 0"
                                    class="min-h-10 rounded-lg px-2 text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Quitar</button>
                            </div>
                            <span x-show="descuentoModo === 'porcentaje' && descuentoValido > 0"
                                class="text-theme-sm text-gray-500 tabular-nums dark:text-gray-400"
                                x-text="'− {{ $moneda }} ' + descuentoValido.toFixed(2)"></span>
                        </div>

                        <p x-show="excedeDescuento" class="text-theme-xs text-warning-700 dark:text-orange-400">
                            @if ($puedeDescontar)
                                Este descuento supera el {{ $descuentoMaximo }}% habitual. Queda registrado a tu nombre.
                            @else
                                Tu rol permite hasta {{ $descuentoMaximo }}%. Por encima necesita autorización.
                            @endif
                        </p>

                        @if ($tasaImpuesto > 0)
                            <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                                <span>Impuesto ({{ number_format($tasaImpuesto * 100, 0) }}%)</span>
                                <span x-text="'{{ $moneda }} ' + impuesto.toFixed(2)"></span>
                            </div>
                        @endif

                        {{-- El total es la cifra que el cajero canta y el cliente
                             mira: se le da su propio bloque para que no compita
                             con el resto de la columna. --}}
                        <div class="mt-1 flex items-baseline justify-between gap-3 rounded-xl bg-brand-50 px-4 py-3 dark:bg-brand-500/10">
                            <span class="font-semibold text-gray-800 dark:text-white/90">Total</span>
                            <span class="text-title-sm font-bold text-brand-600 dark:text-brand-400"
                                x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                        </div>
                    </div>

                    {{-- Pago --}}
                    <div class="space-y-4 border-t border-gray-100 px-5 py-4 dark:border-gray-800">

                        {{-- Una tarjeta por forma de pago. Con una sola línea se ve
                             igual que antes; la segunda aparece solo si el cliente
                             parte el pago. --}}
                        <template x-for="(pago, i) in pagos" :key="i">
                            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">

                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <span class="text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                                        x-text="pagos.length > 1 ? 'Forma de pago ' + (i + 1) : 'Forma de pago'"></span>

                                    <button type="button" x-show="pagos.length > 1" @click="quitarPago(i)"
                                        class="rounded-lg px-2 py-1 text-theme-xs text-gray-400 transition hover:bg-gray-100 hover:text-error-600 dark:hover:bg-white/[0.05]">
                                        Quitar
                                    </button>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <template x-for="m in metodos" :key="m.id">
                                        <button type="button" @click="pago.metodoId = m.id"
                                            :class="pago.metodoId === m.id
                                                ? 'bg-brand-500 text-white'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10'"
                                            class="min-h-11 rounded-lg px-4 py-3 text-theme-xs font-medium transition"
                                            x-text="m.nombre"></button>
                                    </template>
                                </div>

                                {{-- El importe solo hace falta cuando hay más de una
                                     forma: con una sola, cubre el total y punto. --}}
                                <div x-show="pagos.length > 1" class="mt-3">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        Importe
                                        <span x-show="vacio(pago)" class="text-brand-500">— el resto</span>
                                    </label>
                                    <input type="number" inputmode="decimal" step="0.01" min="0" x-model="pago.monto"
                                        :placeholder="montoDe(pago).toFixed(2)"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                        Cubre <span class="font-medium" x-text="'{{ $moneda }} ' + montoDe(pago).toFixed(2)"></span>.
                                        Déjalo vacío para que tome lo que falte.
                                    </p>
                                </div>

                                <div x-show="esEfectivo(pago)" class="mt-3">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        Efectivo recibido
                                    </label>
                                    <input type="number" inputmode="decimal" step="0.01" min="0" x-model.number="pago.recibido"
                                        :placeholder="montoDe(pago).toFixed(2)"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />

                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <template x-for="s in sugerenciasDe(pago)" :key="s">
                                            <button type="button" @click="pago.recibido = s"
                                                class="min-h-11 rounded-lg bg-gray-100 px-3 py-3 text-theme-xs text-gray-600 transition hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10"
                                                x-text="'{{ $moneda }} ' + s.toFixed(2)"></button>
                                        </template>
                                    </div>
                                </div>

                                <div x-show="!esEfectivo(pago) && !esQr(pago)" class="mt-3">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        Número de operación
                                    </label>
                                    <input type="text" x-model="pago.referencia" placeholder="Opcional"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>

                                {{-- Cobro por QR ------------------------------------------------
                                     El código se genera con el importe ya puesto: el cliente
                                     escanea y paga exactamente lo que debe, sin teclear nada.
                                     La venta NO existe todavía; se registra recién cuando el
                                     pago está confirmado. --}}
                                <div x-show="esQr(pago)" class="mt-3">

                                    {{-- Todavía sin generar --}}
                                    <template x-if="!pago.qr">
                                        <div>
                                            <button type="button" @click="generarQr(pago)"
                                                :disabled="montoDe(pago) <= 0 || pago.qrCargando"
                                                class="w-full rounded-lg bg-brand-500 px-3 py-2.5 text-theme-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-white/10">
                                                <span x-show="!pago.qrCargando"
                                                    x-text="'Generar QR por {{ $moneda }} ' + montoDe(pago).toFixed(2)"></span>
                                                <span x-show="pago.qrCargando">Generando…</span>
                                            </button>
                                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                                El código lleva el importe: el cliente no tiene que escribirlo.
                                            </p>
                                        </div>
                                    </template>

                                    {{-- Ya generado: se muestra y se espera --}}
                                    <template x-if="pago.qr">
                                        <div class="rounded-xl border border-gray-200 p-3 text-center dark:border-gray-800">

                                            <div x-show="!pago.qr.pagado" class="flex flex-col items-center">
                                                <canvas :id="'qr-' + pago.qr.id" class="rounded-lg bg-white p-2"></canvas>

                                                <p class="mt-2 text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                                    x-text="'{{ $moneda }} ' + pago.qr.monto.toFixed(2)"></p>

                                                <p class="mt-0.5 flex items-center justify-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span>
                                                    <span x-text="pago.qr.etiqueta"></span>
                                                </p>

                                                {{-- Sin banco detrás, el pago no puede llegar solo:
                                                     se dice, para que nadie crea que está roto. --}}
                                                <p x-show="pago.qr.simulado"
                                                    class="mt-2 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-orange-500/10 dark:text-orange-400">
                                                    Sin banco conectado: el pago se confirma a mano.
                                                </p>

                                                <div class="mt-3 flex w-full flex-wrap gap-2">
                                                    {{-- Confirmar a mano hace falta igual cuando el
                                                         banco está conectado: si su API se cae, el
                                                         cajero mira el comprobante en el celular del
                                                         cliente. Queda con su nombre en la bitácora. --}}
                                                    <button type="button" @click="confirmarQr(pago)"
                                                        class="flex-1 rounded-lg bg-success-500 px-3 py-2 text-theme-xs font-medium text-white transition hover:bg-success-600">
                                                        Ya me pagó
                                                    </button>
                                                    <button type="button" @click="anularQr(pago)"
                                                        class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                                        Cancelar
                                                    </button>
                                                </div>
                                            </div>

                                            <div x-show="pago.qr.pagado" class="py-3">
                                                <p class="text-lg font-semibold text-success-700 dark:text-success-500">
                                                    Pago confirmado
                                                </p>
                                                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400"
                                                    x-text="'{{ $moneda }} ' + pago.qr.monto.toFixed(2)"></p>
                                                <p x-show="pago.qr.referencia" class="mt-1 font-mono text-theme-xs text-gray-500 dark:text-gray-400"
                                                    x-text="pago.qr.referencia"></p>
                                            </div>
                                        </div>
                                    </template>

                                    <p x-show="pago.qrError"
                                        class="mt-2 text-theme-xs text-error-600 dark:text-error-400"
                                        x-text="pago.qrError"></p>
                                </div>
                            </div>
                        </template>

                        <button type="button" @click="agregarPago()"
                            x-show="pagos.length < metodos.length && total > 0"
                            class="min-h-11 w-full rounded-lg border border-dashed border-gray-300 px-3 py-3 text-theme-xs font-medium text-gray-500 transition hover:border-brand-400 hover:text-brand-500 dark:border-gray-700 dark:text-gray-400">
                            + Dividir el pago en otra forma
                        </button>

                        {{-- Lo que todavía no está repartido: es el número que el
                             cajero mira cuando el cliente paga en dos partes. --}}
                        <div x-show="pagos.length > 1 && lineasSinMonto === 0 && Math.abs(restante) >= 0.005"
                            class="flex items-baseline justify-between rounded-xl px-4 py-3"
                            :class="restante > 0 ? 'bg-warning-50 dark:bg-orange-500/10' : 'bg-error-50 dark:bg-error-500/10'">
                            <span class="text-theme-sm font-medium"
                                :class="restante > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-error-600 dark:text-error-400'"
                                x-text="restante > 0 ? 'Falta por asignar' : 'Asignado de más'"></span>
                            <span class="text-lg font-semibold"
                                :class="restante > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-error-600 dark:text-error-400'"
                                x-text="'{{ $moneda }} ' + Math.abs(restante).toFixed(2)"></span>
                        </div>

                        <div x-show="vuelto > 0"
                            class="flex items-baseline justify-between rounded-xl bg-success-50 px-4 py-3 dark:bg-success-500/10">
                            <span class="text-theme-sm font-medium text-success-700 dark:text-success-500">Vuelto</span>
                            <span class="text-lg font-semibold text-success-700 dark:text-success-500"
                                x-text="'{{ $moneda }} ' + vuelto.toFixed(2)"></span>
                        </div>
                    </div>
                    {{-- Cierra la zona con scroll: de aquí para abajo el pie
                         (botón de cobrar) queda fuera y siempre a la vista. --}}
                    </div>

                    {{-- Deshabilitado se ve gris, no azul claro: un botón azul
                         que no responde parece un fallo. Y en vez de repetir
                         «Cobrar Bs 0.00» dice qué falta para poder cobrar. --}}
                    <div class="flex-none space-y-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        <button type="submit" x-ref="cobrar" :disabled="!puedeCobrar || enviando || verificando"
                            class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 text-base font-semibold text-white transition hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-500 dark:disabled:bg-white/[0.06] dark:disabled:text-gray-500">
                            <template x-if="enviando">
                                <span>Registrando…</span>
                            </template>
                            <template x-if="!enviando && verificando">
                                <span>Comprobando precios…</span>
                            </template>
                            <template x-if="!enviando && !verificando && !carrito.length">
                                <span>Agrega un producto para cobrar</span>
                            </template>
                            <template x-if="!enviando && !verificando && carrito.length">
                                <span class="flex items-center gap-2">
                                    <span x-text="'Cobrar {{ $moneda }} ' + total.toFixed(2)"></span>
                                    <kbd class="tecla" x-show="puedeCobrar">F4</kbd>
                                </span>
                            </template>
                        </button>

                        <p x-show="precioActualizado && !enviando" x-cloak
                            class="text-center text-theme-xs font-medium text-warning-700 dark:text-orange-400">
                            El precio o el stock de algún producto cambió — revisa el total y vuelve a cobrar.
                        </p>
                        <p x-show="carrito.length && !puedeCobrar && !enviando && !precioActualizado"
                            class="text-center text-theme-xs text-gray-500 dark:text-gray-400" x-text="motivoBloqueo"></p>
                    </div>
                </form>
            </div>

            {{-- Barra flotante: en una pantalla de teléfono el carrito queda
                 debajo de toda la cuadrícula, así que el total y el acceso a
                 cobrar acompañan siempre al cajero. --}}
            <div x-show="carrito.length" x-cloak
                class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 p-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 xl:hidden">
                <button type="button" @click="$refs.carrito.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="flex w-full items-center justify-between gap-3 rounded-xl bg-brand-500 px-4 py-3 text-white transition hover:bg-brand-600">
                    <span class="flex items-center gap-2 text-theme-sm">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                            <path d="M2.75 4h1.6a1 1 0 0 1 .98.8l.42 2.1m0 0 1.5 7.1a1.5 1.5 0 0 0 1.47 1.2h8.06a1.5 1.5 0 0 0 1.4-.96l2.32-5.83a1 1 0 0 0-.93-1.37H5.75Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-text="carrito.length"></span>
                        <span x-text="carrito.length === 1 ? 'artículo' : 'artículos'"></span>
                    </span>
                    <span class="text-base font-semibold" x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                </button>
            </div>

            {{-- Alta rápida de cliente, sin salir del mostrador: solo los
                 campos que la venta realmente necesita. Cambiar de rubro,
                 desactivar o editar datos de contacto sigue siendo cosa del
                 módulo de Clientes. --}}
            <div x-show="nuevoClienteAbierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-nuevo-cliente"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="nuevoClienteAbierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="nuevoClienteAbierto"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-nuevo-cliente" class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">Registrar cliente</h2>
                    <p class="mb-6 text-theme-xs text-gray-500 dark:text-gray-400">
                        Se guarda y queda elegido para esta venta, sin perder lo que ya llevas en el carrito.
                    </p>

                    <div class="space-y-5">
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" @click="nuevoCliente.persona = 'NATURAL'; nuevoCliente.tipo_documento = 'CI'"
                                :class="nuevoCliente.persona === 'NATURAL'
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                                    : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                                class="rounded-xl border-2 px-4 py-3 text-left transition">
                                <span class="block text-sm font-medium">Persona natural</span>
                                <span class="block text-theme-xs opacity-75">Recibe recibo</span>
                            </button>
                            <button type="button" @click="nuevoCliente.persona = 'JURIDICA'; nuevoCliente.tipo_documento = 'NIT'"
                                :class="nuevoCliente.persona === 'JURIDICA'
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                                    : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                                class="rounded-xl border-2 px-4 py-3 text-left transition">
                                <span class="block text-sm font-medium">Persona jurídica</span>
                                <span class="block text-theme-xs opacity-75">Recibe factura</span>
                            </button>
                        </div>

                        <template x-if="nuevoCliente.persona === 'NATURAL'">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombres</label>
                                    <input x-model="nuevoCliente.nombres" placeholder="Carlos"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">Apellidos</label>
                                    <input x-model="nuevoCliente.apellidos" placeholder="Mendoza Ríos"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tipo de documento</label>
                                    <select x-model="nuevoCliente.tipo_documento"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                        <option value="CI">CI</option>
                                        <option value="CE">Carné de extranjería</option>
                                        <option value="PAS">Pasaporte</option>
                                        <option value="SIN">Sin documento</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">Documento</label>
                                    <input x-model="nuevoCliente.documento" placeholder="45678901"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>
                            </div>
                        </template>

                        <template x-if="nuevoCliente.persona === 'JURIDICA'">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">Razón social</label>
                                    <input x-model="nuevoCliente.razon_social" placeholder="Servicios Generales Perú S.A.C."
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        NIT <span class="text-error-600 dark:text-error-400">*</span>
                                    </label>
                                    <input x-model="nuevoCliente.documento" placeholder="20512345678"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Sin NIT no se puede emitir factura.</p>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        Dirección <span class="text-error-600 dark:text-error-400">*</span>
                                    </label>
                                    <input x-model="nuevoCliente.direccion" placeholder="Av. Industrial 1420, Lima"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Obligatoria para la factura: es la dirección fiscal.</p>
                                </div>
                            </div>
                        </template>

                        <div x-show="nuevoClienteError" x-cloak>
                            <x-ui.alert variant="error" title="No se pudo registrar" :message="null">
                                <span x-text="nuevoClienteError"></span>
                            </x-ui.alert>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="nuevoClienteAbierto = false">Cancelar</x-ui.button>
                            <x-ui.button type="button" size="sm" @click="guardarNuevoCliente()"
                                x-bind:disabled="nuevoClienteGuardando">
                                <span x-text="nuevoClienteGuardando ? 'Guardando…' : 'Guardar y elegir'"></span>
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @push('scripts')
            <script>
                function mostrador() {
                    return {
                        q: '',
                        buscandoCodigo: false,
                        codigoNoEncontrado: '',
                        categoria: '',
                        productos: [],
                        carrito: [],
                        clienteId: {{ (int) request('cliente') ?: 'null' }},
                        descuento: 0,
                        descuentoModo: 'monto', // 'monto' o 'porcentaje': cómo se lee `descuento`
                        /* Formas de pago de esta venta. El cliente puede pagar una
                           parte en efectivo y otra por QR o tarjeta, así que esto es
                           una lista y no un método suelto.

                           `monto` vacío significa «el resto»: es la misma convención
                           que ya entendía `Ventas::registrarPagos`, donde UNA línea
                           puede omitir el importe y el servidor le asigna lo que
                           falta. Dejarlo así evita que el navegador y el servidor
                           discutan por un céntimo de redondeo. */
                        pagos: [{
                            metodoId: {{ $metodosPago->first()?->id ?? 'null' }},
                            monto: '',
                            recibido: null,
                            referencia: '',
                            qr: null,          // el cobro generado, mientras se espera al cliente
                            qrCargando: false,
                            qrError: '',
                        }],
                        metodosQr: @js($metodosQr),
                        qrSimulado: {{ $qrSimulado ? 'true' : 'false' }},
                        qrSegundos: {{ $qrSegundosConsulta }},
                        rutaQrCrear: '{{ route('qr.crear') }}',
                        rutaQr: '{{ url('pos/qr') }}',
                        enviando: false,
                        verificando: false,
                        precioActualizado: false,

                        // Alta rápida de cliente desde el mostrador.
                        clientesNuevos: [],
                        nuevoClienteAbierto: false,
                        nuevoClienteGuardando: false,
                        nuevoClienteError: '',
                        nuevoCliente: {
                            persona: 'NATURAL', tipo_documento: 'CI', documento: '',
                            nombres: '', apellidos: '', razon_social: '', direccion: '',
                        },

                        // Nombre de categoría por id, para la etiqueta de la tarjeta.
                        categorias: @js($categorias->pluck('nombre', 'id')),

                        tasa: {{ $tasaImpuesto }},
                        maxDescuento: {{ $descuentoMaximo }},
                        puedeDescontar: {{ $puedeDescontar ? 'true' : 'false' }},
                        efectivos: @js($metodosPago->where('codigo', 'EFECTIVO')->pluck('id')->values()),
                        metodos: @js($metodosPago->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre])->values()),

                        async cargar() {
                            const url = new URL('{{ route('pos.productos') }}', window.location.origin);
                            url.searchParams.set('q', this.q);
                            if (this.categoria) url.searchParams.set('categoria', this.categoria);

                            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            this.productos = await respuesta.json();
                        },

                        abrirNuevoCliente() {
                            this.nuevoCliente = {
                                persona: 'NATURAL', tipo_documento: 'CI', documento: '',
                                nombres: '', apellidos: '', razon_social: '', direccion: '',
                            };
                            this.nuevoClienteError = '';
                            this.nuevoClienteAbierto = true;
                        },

                        /* Alta rápida sin salir del mostrador: la misma validación del
                           módulo de Clientes, pero por fetch — así el carrito en curso
                           no se pierde con una navegación de página completa. */
                        async guardarNuevoCliente() {
                            this.nuevoClienteError = '';
                            this.nuevoClienteGuardando = true;

                            const c = this.nuevoCliente;
                            const datos = { tipo_persona: c.persona, tipo_documento: c.tipo_documento, documento: c.documento };

                            if (c.persona === 'JURIDICA') {
                                datos.razon_social = c.razon_social;
                                datos.direccion = c.direccion;
                            } else {
                                datos.nombres = c.nombres;
                                datos.apellidos = c.apellidos;
                            }

                            try {
                                const respuesta = await fetch('{{ route('clientes.store') }}', {
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
                                    this.nuevoClienteError = primero ?? cuerpo.message ?? 'No se pudo registrar el cliente.';
                                    return;
                                }

                                this.clientesNuevos.push(cuerpo);
                                this.clienteId = cuerpo.id;
                                this.nuevoClienteAbierto = false;
                            } catch (e) {
                                this.nuevoClienteError = 'No se pudo conectar con el servidor. Intenta de nuevo.';
                            } finally {
                                this.nuevoClienteGuardando = false;
                            }
                        },

                        /*
                         * Con el lector, el código llega completo y termina en Enter.
                         *
                         * La búsqueda tiene 250 ms de espera antes de consultar al
                         * servidor, y una pistola teclea el código entero en tres
                         * milisegundos: cuando llega el Enter, la lista en pantalla
                         * todavía es la anterior. Si acá se resolviera contra esa
                         * lista, escanear un producto que no estuviera en ella
                         * agregaría EL PRIMERO DE LA PANTALLA, en silencio, y el
                         * cajero cobraría otra cosa. Con catorce productos no se
                         * nota porque están todos en memoria; con un catálogo de
                         * verdad —el servidor manda de a 24— pasaría a diario.
                         *
                         * Por eso primero se consulta y recién después se decide.
                         * Cuesta un viaje al servidor por escaneo; cobrar mal cuesta
                         * mucho más.
                         */
                        async porCodigo() {
                            const texto = this.q.trim();

                            if (! texto || this.buscandoCodigo) {
                                return;
                            }

                            this.buscandoCodigo = true;

                            try {
                                await this.cargar();

                                /* Si mientras se consultaba llegó otro escaneo, esta
                                   respuesta ya no corresponde: la manda la siguiente. */
                                if (this.q.trim() !== texto) {
                                    return;
                                }

                                const exacto = this.productos.find(
                                    p => p.codigo_barras === texto || p.codigo === texto
                                );

                                /* Solo se cae en «el primero» cuando lo tecleado NO
                                   parece un código: escribir «papaya» y pulsar Enter
                                   sigue funcionando. Un código sin coincidencia exacta
                                   se avisa, no se adivina.

                                   La regla es estrecha a propósito: ocho dígitos o más
                                   —un EAN-13 tiene trece, un UPC doce— o el formato del
                                   código interno, «P-1001». Una primera versión aceptaba
                                   cualquier cosa alfanumérica de seis o más y rompió la
                                   búsqueda por nombre: «papaya» pasaba por código. */
                                const pareceCodigo = /^\d{8,}$/.test(texto)
                                    || /^[A-Za-z]{1,4}-\d+$/.test(texto);
                                const elegido = exacto ?? (pareceCodigo ? null : this.productos[0]);

                                if (! elegido) {
                                    this.codigoNoEncontrado = texto;
                                    return;
                                }

                                this.codigoNoEncontrado = '';
                                this.agregar(elegido);
                                this.q = '';
                                await this.cargar();
                            } finally {
                                this.buscandoCodigo = false;
                            }
                        },

                        agregar(p) {
                            if (p.stock <= 0) return;

                            const linea = this.carrito.find(l => l.producto_id === p.id);

                            if (linea) {
                                if (linea.cantidad + 1 > p.stock) return;
                                linea.cantidad = Math.round((linea.cantidad + 1) * 1000) / 1000;
                                return;
                            }

                            this.carrito.push({
                                producto_id: p.id,
                                nombre: p.nombre,
                                precio: p.precio,
                                precio_estante: p.precio_estante,
                                afecto: p.afecto,
                                cantidad: 1,
                                stock: p.stock,
                                unidad: p.unidad,
                                decimal: p.decimal,
                            });
                        },

                        sumar(i, delta) {
                            const l = this.carrito[i];
                            const paso = l.decimal ? 0.5 : 1;
                            const nueva = Math.round((l.cantidad + delta * paso) * 1000) / 1000;

                            if (nueva <= 0) return this.quitar(i);
                            if (nueva > l.stock) return;

                            l.cantidad = nueva;
                        },

                        normalizar(i) {
                            const l = this.carrito[i];
                            let c = Number(l.cantidad) || 0;

                            if (!l.decimal) c = Math.round(c);
                            if (c <= 0) return this.quitar(i);

                            l.cantidad = Math.min(c, l.stock);
                        },

                        quitar(i) {
                            this.carrito.splice(i, 1);
                        },

                        cantidadTexto(n) {
                            return Number(n).toFixed(3).replace(/\.?0+$/, '');
                        },

                        /* Cuánto de este producto va ya en el carrito, o 0. */
                        enCarrito(productoId) {
                            const linea = this.carrito.find((l) => l.producto_id === productoId);

                            return linea ? linea.cantidad : 0;
                        },

                        /* Cuántos artículos lleva el carrito en total. */
                        get articulos() {
                            return this.carrito.reduce((s, l) => s + Number(l.cantidad), 0);
                        },

                        /* Nombre de la categoría para la etiqueta de la tarjeta. */
                        nombreCategoria(id) {
                            return this.categorias[id] || '';
                        },

                        /* Color de fondo de la etiqueta de categoría, repartido
                           por id para que la cuadrícula no sea un muro del mismo
                           tono; el resto van en gris. */
                        tonoCategoria(id) {
                            const tonos = [
                                'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
                                'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
                                'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
                                'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
                                'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
                            ];

                            return id ? tonos[id % tonos.length] : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400';
                        },

                        /* Los precios se guardan sin impuesto; el total lo lleva encima. */
                        get subtotal() {
                            return this.redondear(
                                this.carrito.reduce((s, l) => s + this.redondear(l.precio * l.cantidad), 0)
                            );
                        },

                        get impuesto() {
                            /* Se redondea POR LÍNEA, igual que la columna generada
                               `impuesto_linea`: sumar sin redondear daría otro total. */
                            const bruto = this.carrito.reduce(
                                (s, l) => s + (l.afecto
                                    ? this.redondear(this.redondear(l.precio * l.cantidad) * this.tasa)
                                    : 0), 0
                            );
                            /* El descuento de cabecera se prorratea, igual que en sp_recalcular_venta. */
                            const factor = this.subtotal > 0 ? (this.subtotal - this.descuentoValido) / this.subtotal : 0;

                            return this.redondear(bruto * factor);
                        },

                        /* Siempre el monto, aunque se haya tecleado en %. El
                           porcentaje se redondea hacia ABAJO al céntimo: 10 % de
                           7,95 es 0,79 y no 0,80, que ya sería 10,06 % y el servidor
                           se lo rechazaría a un cajero con tope de 10 %. */
                        get descuentoValido() {
                            const d = Math.max(Number(this.descuento) || 0, 0);

                            if (this.descuentoModo === 'porcentaje') {
                                const centavos = Math.round(this.subtotal * 100);
                                return Math.floor(centavos * Math.min(d, 100) / 100 + 1e-9) / 100;
                            }

                            return Math.min(d, this.subtotal);
                        },

                        descontarPorcentaje(porcentaje) {
                            this.descuentoModo = 'porcentaje';
                            this.descuento = porcentaje;
                        },

                        get total() {
                            return this.redondear(this.subtotal - this.descuentoValido + this.impuesto);
                        },

                        get excedeDescuento() {
                            if (this.descuentoValido <= 0 || this.subtotal <= 0) return false;
                            // En centavos, igual que el servidor: 10,89 de 108,90 es 10 % justo.
                            return Math.round(this.descuentoValido * 100) * 100 > this.maxDescuento * Math.round(this.subtotal * 100);
                        },

                        /* ---------------------------------------- formas de pago */

                        esEfectivo(pago) {
                            return this.efectivos.includes(pago.metodoId);
                        },

                        vacio(pago) {
                            return pago.monto === '' || pago.monto === null;
                        },

                        get lineasSinMonto() {
                            return this.pagos.filter(p => this.vacio(p)).length;
                        },

                        /* Lo ya repartido entre las líneas que sí tienen importe. */
                        get asignado() {
                            return this.redondear(this.pagos.reduce(
                                (suma, p) => suma + (this.vacio(p) ? 0 : Number(p.monto) || 0), 0));
                        },

                        get restante() {
                            return this.redondear(this.total - this.asignado);
                        },

                        /* Lo que cubre esta línea: su importe, o el resto si la
                           dejaron en blanco y es la única así. */
                        montoDe(pago) {
                            if (!this.vacio(pago)) return this.redondear(Number(pago.monto) || 0);

                            return this.lineasSinMonto === 1 ? Math.max(this.restante, 0) : 0;
                        },

                        vueltoDe(pago) {
                            if (!this.esEfectivo(pago) || pago.recibido === null || pago.recibido === '') return 0;

                            return this.redondear(Math.max((Number(pago.recibido) || 0) - this.montoDe(pago), 0));
                        },

                        get vuelto() {
                            return this.redondear(this.pagos.reduce((s, p) => s + this.vueltoDe(p), 0));
                        },

                        agregarPago() {
                            // El método que se propone es el primero que aún no se usó.
                            const usados = this.pagos.map(p => p.metodoId);
                            const libre = this.metodos.find(m => !usados.includes(m.id));

                            // Al abrir una segunda línea, la primera deja de ser «el
                            // resto» y toma un importe concreto: si no, quedarían dos
                            // líneas en blanco y no se sabría cuál cubre qué.
                            if (this.lineasSinMonto >= 1) {
                                this.pagos.forEach(p => {
                                    if (this.vacio(p)) p.monto = this.montoDe(p).toFixed(2);
                                });
                            }

                            this.pagos.push({
                                metodoId: libre ? libre.id : this.metodos[0].id,
                                monto: '',
                                recibido: null,
                                referencia: '',
                                qr: null,
                                qrCargando: false,
                                qrError: '',
                            });
                        },

                        /* ------------------------------------------- cobro por QR */

                        esQr(pago) {
                            return this.metodosQr.includes(pago.metodoId);
                        },

                        /* Una línea de QR solo sirve si su cobro está pagado. */
                        qrPendiente(pago) {
                            return this.esQr(pago) && !(pago.qr && pago.qr.pagado);
                        },

                        get cabecera() {
                            return {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            };
                        },

                        async generarQr(pago) {
                            const monto = this.montoDe(pago);
                            if (monto <= 0 || pago.qrCargando) return;

                            pago.qrCargando = true;
                            pago.qrError = '';

                            try {
                                const r = await fetch(this.rutaQrCrear, {
                                    method: 'POST',
                                    headers: this.cabecera,
                                    body: JSON.stringify({ monto: monto.toFixed(2) }),
                                });
                                const datos = await r.json();

                                if (!r.ok) {
                                    pago.qrError = datos.error ?? 'No se pudo generar el QR.';
                                    return;
                                }

                                pago.qr = datos;
                                this.$nextTick(() => this.pintarQr(pago));
                                this.vigilarQr(pago);
                            } catch (e) {
                                pago.qrError = 'No se pudo generar el QR.';
                            } finally {
                                pago.qrCargando = false;
                            }
                        },

                        pintarQr(pago) {
                            const lienzo = document.getElementById('qr-' + pago.qr.id);
                            if (lienzo && window.dibujarQr) window.dibujarQr(lienzo, pago.qr.payload);
                        },

                        /* Le pregunta al banco cada pocos segundos si ya pagaron.
                           Se detiene solo cuando el cobro deja de estar pendiente,
                           para no quedar consultando de por vida. */
                        vigilarQr(pago) {
                            if (!pago.qr || pago.qr.estado !== 'PENDIENTE') return;

                            const id = pago.qr.id;

                            setTimeout(async () => {
                                /* Si el cajero cambió de método o generó otro QR,
                                   esta vigilancia ya no corresponde. */
                                if (!pago.qr || pago.qr.id !== id) return;

                                try {
                                    const r = await fetch(this.rutaQr + '/' + id, {
                                        headers: { 'Accept': 'application/json' },
                                    });
                                    if (r.ok) pago.qr = await r.json();
                                } catch (e) {
                                    /* Sin conexión con el banco: se reintenta en la
                                       vuelta siguiente, no se rompe la pantalla. */
                                }

                                this.vigilarQr(pago);
                            }, this.qrSegundos * 1000);
                        },

                        /* El cajero ve el comprobante en el celular del cliente y lo
                           da por pagado. Queda registrado con su nombre. */
                        async confirmarQr(pago) {
                            if (!pago.qr) return;

                            const r = await fetch(this.rutaQr + '/' + pago.qr.id + '/confirmar', {
                                method: 'POST',
                                headers: this.cabecera,
                                body: JSON.stringify({ referencia: pago.referencia || null }),
                            });
                            const datos = await r.json();

                            if (r.ok) pago.qr = datos;
                            else pago.qrError = datos.error ?? 'No se pudo confirmar el pago.';
                        },

                        async anularQr(pago) {
                            if (!pago.qr) return;

                            await fetch(this.rutaQr + '/' + pago.qr.id + '/anular', {
                                method: 'POST',
                                headers: this.cabecera,
                            });

                            pago.qr = null;
                            pago.qrError = '';
                        },

                        quitarPago(indice) {
                            if (this.pagos.length <= 1) return;
                            this.pagos.splice(indice, 1);

                            // Si queda una sola, vuelve a ser «el resto».
                            if (this.pagos.length === 1) this.pagos[0].monto = '';
                        },

                        /* Billetes con los que suele pagar la gente, para el importe
                           que cubre ESTA línea (que no siempre es el total). */
                        sugerenciasDe(pago) {
                            const t = this.montoDe(pago);
                            if (t <= 0) return [];

                            const billetes = [10, 20, 50, 100, 200];
                            const opciones = new Set([Math.ceil(t)]);

                            billetes.filter(b => b >= t).forEach(b => opciones.add(b));

                            return [...opciones].sort((a, b) => a - b).slice(0, 4);
                        },

                        get sinStock() {
                            return this.carrito.some(l => l.cantidad > l.stock);
                        },

                        /* Un efectivo al que no se le puso «recibido» se toma como
                           justo: el cajero no siempre lo teclea si le dieron exacto. */
                        efectivoCorto(pago) {
                            return this.esEfectivo(pago)
                                && pago.recibido !== null && pago.recibido !== ''
                                && Number(pago.recibido) < this.montoDe(pago);
                        },

                        get pagoCubierto() {
                            if (this.lineasSinMonto > 1) return false;

                            return this.lineasSinMonto === 1
                                ? this.restante > 0                                 // la línea en blanco toma el resto
                                : Math.abs(this.asignado - this.total) < 0.005;     // todas con importe: tienen que sumar
                        },

                        get puedeCobrar() {
                            if (!this.carrito.length || this.total <= 0 || this.sinStock) return false;
                            if (this.excedeDescuento && !this.puedeDescontar) return false;
                            if (!this.pagoCubierto) return false;
                            if (this.pagos.some(p => this.efectivoCorto(p))) return false;
                            if (this.pagos.some(p => this.qrPendiente(p))) return false;

                            return true;
                        },

                        get motivoBloqueo() {
                            if (this.sinStock) return 'Hay líneas por encima del stock disponible.';
                            if (this.excedeDescuento && !this.puedeDescontar) return 'El descuento necesita autorización.';
                            if (this.lineasSinMonto > 1) return 'Solo una forma de pago puede quedar sin importe.';
                            if (this.lineasSinMonto === 1 && this.restante <= 0) return 'Las formas de pago ya cubren el total.';
                            if (this.lineasSinMonto === 0 && this.restante > 0) {
                                return 'Faltan {{ $moneda }} ' + this.restante.toFixed(2) + ' por asignar.';
                            }
                            if (this.lineasSinMonto === 0 && this.restante < 0) {
                                return 'Las formas de pago suman {{ $moneda }} ' + Math.abs(this.restante).toFixed(2) + ' de más.';
                            }
                            if (this.pagos.some(p => this.efectivoCorto(p))) return 'El efectivo recibido no alcanza.';
                            if (this.pagos.some(p => this.qrPendiente(p))) return 'Falta que se confirme el pago por QR.';
                            return '';
                        },

                        redondear(n) {
                            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
                        },

                        /* El carrito guarda el precio de cada línea desde que se agregó.
                           Si alguien edita el producto mientras el cajero todavía no
                           cobra, la pantalla queda con un total/vuelto viejo aunque el
                           servidor siempre cobre el precio de catálogo actual — y si el
                           precio bajó, el backend no tiene forma de notarlo (el efectivo
                           recibido igual alcanza), así que la venta se registraría sin
                           error con un vuelto real distinto al que ya se le dio al
                           cliente mirando la pantalla. Por eso se refresca el carrito
                           contra el catálogo justo antes de enviar, y si algo cambió se
                           detiene: el cajero ve el total correcto y confirma de nuevo. */
                        async confirmarYEnviar(e) {
                            if (!this.puedeCobrar || this.enviando || this.verificando) return;

                            this.verificando = true;
                            this.precioActualizado = false;

                            try {
                                if (await this.refrescarPrecios()) {
                                    this.precioActualizado = true;
                                    return;
                                }
                            } finally {
                                this.verificando = false;
                            }

                            this.preparar(e);
                            this.$refs.carrito.submit();
                        },

                        /* Compara precio/stock del carrito contra el catálogo. Devuelve
                           true si algo cambió (y ya actualizó las líneas en el sitio). */
                        async refrescarPrecios() {
                            const ids = [...new Set(this.carrito.map(l => l.producto_id))];
                            if (!ids.length) return false;

                            const url = new URL('{{ route('pos.precios') }}', window.location.origin);
                            url.searchParams.set('ids', ids.join(','));

                            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            const porId = Object.fromEntries((await respuesta.json()).map(p => [p.id, p]));

                            let cambio = false;

                            this.carrito.forEach(l => {
                                const p = porId[l.producto_id];
                                if (!p) return; // se desactivó: el backend lo frena al cobrar

                                if (p.precio !== l.precio || p.precio_estante !== l.precio_estante) {
                                    l.precio = p.precio;
                                    l.precio_estante = p.precio_estante;
                                    cambio = true;
                                }

                                if (p.stock !== l.stock) {
                                    l.stock = p.stock;
                                    if (l.cantidad > p.stock) l.cantidad = p.stock;
                                    cambio = true;
                                }
                            });

                            return cambio;
                        },

                        /* Se arma el formulario en el momento de enviar: el carrito vive en Alpine. */
                        preparar(e) {
                            this.enviando = true;

                            const campos = this.$refs.campos;
                            campos.innerHTML = '';

                            const oculto = (name, value) => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = name;
                                input.value = value;
                                campos.appendChild(input);
                            };

                            this.carrito.forEach((l, i) => {
                                oculto(`lineas[${i}][producto_id]`, l.producto_id);
                                oculto(`lineas[${i}][cantidad]`, l.cantidad);
                                oculto(`lineas[${i}][precio_unitario]`, l.precio);
                            });

                            /* De la línea que va «por el resto» NO se manda el importe:
                               lo calcula el servidor sobre su propio total, así un
                               céntimo de diferencia en el redondeo del navegador no
                               puede tumbar la venta. Las demás sí lo llevan, porque
                               son un reparto que decidió el cajero. */
                            this.pagos.forEach((p, i) => {
                                oculto(`pagos[${i}][metodo_pago_id]`, p.metodoId);

                                if (!this.vacio(p)) {
                                    oculto(`pagos[${i}][monto]`, this.montoDe(p).toFixed(2));
                                }

                                if (this.esEfectivo(p)) {
                                    if (p.recibido !== null && p.recibido !== '' && Number(p.recibido) >= this.montoDe(p)) {
                                        oculto(`pagos[${i}][monto_recibido]`, Number(p.recibido).toFixed(2));
                                    }
                                } else if (p.referencia) {
                                    oculto(`pagos[${i}][referencia]`, p.referencia);
                                }

                                /* El cobro por QR viaja con su línea: el servidor
                                   comprueba que esté pagado, libre y por el mismo
                                   importe antes de registrar la venta. */
                                if (p.qr && p.qr.pagado) {
                                    oculto(`pagos[${i}][cobro_qr_id]`, p.qr.id);
                                }
                            });

                            if (this.clienteId) oculto('cliente_id', this.clienteId);
                            if (this.descuentoValido > 0) oculto('descuento', this.descuentoValido.toFixed(2));
                        },

                        atajos(e) {
                            if (e.key === 'F2') {
                                e.preventDefault();
                                this.$refs.buscador.focus();
                                this.$refs.buscador.select();
                            }

                            if (e.key === 'F4' && this.puedeCobrar) {
                                e.preventDefault();
                                this.$refs.cobrar.click();
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endunless
@endsection
