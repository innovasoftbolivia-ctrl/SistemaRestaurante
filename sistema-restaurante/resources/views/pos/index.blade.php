@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    @unless ($sesion)
        {{-- Sin caja abierta no se puede cobrar: no habría dónde imputar el
             dinero. El turno se abre aquí mismo y se vuelve a vender, sin pasar
             por la pantalla de Caja: es lo primero que hace el cajero cada día. --}}
        <div class="mx-auto max-w-lg" data-abrir-caja-desde-pos>
            <x-common.component-card title="Abre tu caja para empezar a cobrar"
                desc="Cuenta el efectivo con el que empiezas el turno. De ahí parte el arqueo al cerrar.">
                @if (! auth()->user()->tienePermiso('caja.abrir'))
                    <p class="text-sm text-gray-600 dark:text-gray-400">Tu rol no abre caja: pídele a quien la maneja que la abra.</p>
                @elseif ($cajasLibres->isEmpty())
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                        Todas las cajas del local tienen un turno abierto. Para cobrar desde aquí hace falta una libre.
                    </p>
                    <x-ui.button :href="route('caja.index')" variant="outline" class="w-full">Ver las cajas</x-ui.button>
                @else
                    @include('caja._abrir', ['cajasLibres' => $cajasLibres, 'fondos' => $fondos, 'volver' => 'pos'])
                @endif
            </x-common.component-card>
        </div>
    @else
        {{-- `pb-28` deja sitio en móvil a la barra flotante del carrito. --}}
        <div x-data="mostrador()" x-init="restaurarVentaEnCurso(); cargar(); $nextTick(() => $refs.buscador?.focus())"
        @keydown.window="atajos($event)"
            class="grid grid-cols-1 gap-6 pb-28 xl:grid-cols-5 xl:pb-0">

            {{-- El menú --}}
            <div class="space-y-4 xl:col-span-3">
                {{-- Volver a cobrar: el camino de corrección. Solo aparecen
                     cuando se anuló la venta de un pedido —se cobró con la forma
                     de pago equivocada, por ejemplo—. El cliente ya tiene su
                     ticket con ese número y la cocina lo sigue preparando: se
                     cobra de nuevo con el mismo número, o se cancela. Volver a
                     cargarlo aquí como venta nueva daría otro número y otra
                     comanda por los mismos platos. --}}
                @if ($avisoCocina)
                    {{-- Se canceló un pedido que la cocina ya tenía en papel: que se
                         entere también en papel, o lo prepara igual. --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-warning-300 bg-warning-50 p-5 dark:border-orange-500/30 dark:bg-orange-500/10" data-aviso-cocina>
                        <p class="text-theme-sm font-medium text-warning-800 dark:text-orange-300">
                            El {{ $avisoCocina->numero_visible }} ya estaba en la cocina: imprime el aviso de cancelación.
                        </p>
                        <form method="POST" action="{{ route('pedidos.comanda.imprimir', $avisoCocina) }}" target="_blank">
                            @csrf
                            <x-ui.button type="submit" size="sm">Imprimir aviso</x-ui.button>
                        </form>
                    </div>
                @endif

                @if ($porCobrar->isNotEmpty())
                    <div class="rounded-2xl border border-warning-300 bg-warning-50 p-5 dark:border-orange-500/30 dark:bg-orange-500/10" data-pedidos-por-cobrar>
                        <h2 class="text-base font-semibold text-warning-800 dark:text-orange-300">Volver a cobrar</h2>
                        <p class="mt-1 text-theme-xs text-warning-700 dark:text-orange-400">
                            Pedidos cuyo cobro se anuló. Cóbralos de nuevo con su mismo número —el cliente ya tiene el
                            ticket y la cocina ya los prepara—, o cancélalos. No los vuelvas a cargar como venta nueva.
                        </p>
                        <ul class="mt-3 space-y-2">
                            @foreach ($porCobrar as $pendiente)
                                <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 dark:bg-white/[0.03]">
                                    <div class="min-w-0">
                                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $pendiente->etiqueta }}</p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ Config::importe(\App\Services\Pedidos::totalDe($pendiente)) }}
                                            · desde las {{ $pendiente->fecha_apertura?->format('H:i') }}
                                        </p>
                                    </div>
                                    <x-ui.button size="sm" :href="route('pedidos.cobrar', $pendiente)">Cobrar o cancelar</x-ui.button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                                placeholder="Nombre o código del plato — luego Enter"
                                class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-12 w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-4 pl-12 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                    </div>

                    {{-- Un código tecleado que no está en el menú se dice.
                         Antes se agregaba el primero de la pantalla y el cajero
                         cobraba otra cosa sin enterarse. --}}
                    <div x-show="codigoNoEncontrado" x-cloak
                        class="mt-3 flex items-center gap-2 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
                        <span>No hay nada con el código
                            <strong x-text="codigoNoEncontrado"></strong>. Revisa que esté cargado en el menú.</span>
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
                         platos hay en cada categoría y se elige de un toque.
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
                                class="inline-flex flex-none min-h-11 items-center gap-1.5 rounded-full px-4 py-3 text-theme-xs font-medium transition">
                                <span class="h-2 w-2 rounded-full ring-1 ring-white/60 dark:ring-black/20" :class="franjaCategoria({{ $categoria->id }})" aria-hidden="true"></span>
                                {{ $categoria->nombre }}
                                <span class="opacity-60">{{ $categoria->productos_count }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Tarjetas bajas, de texto: en un restaurante el cajero reconoce
                     el plato por el nombre, y casi ninguno tiene foto. Antes cada
                     tarjeta reservaba media altura a un recuadro gris de «sin
                     foto», y cabían ocho platos por pantalla; así caben el doble.
                     Dos columnas en el teléfono, tres en tableta y cuatro de ahí
                     en adelante. --}}
                <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="p in productos" :key="p.id">
                        <button type="button" @click="agregar(p)"
                            :title="nombreCategoria(p.categoria_id)"
                            class="group relative flex min-h-[5.5rem] items-stretch overflow-hidden rounded-xl border border-gray-200 bg-white text-left transition hover:border-brand-300 hover:shadow-theme-md active:scale-[0.98] focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 motion-reduce:active:scale-100 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">

                            {{-- La franja dice la categoría con el mismo color que su
                                 ficha de filtro de arriba: se aprende sin leer, y
                                 reemplaza la etiqueta con texto que llenaba la
                                 tarjeta. --}}
                            <span class="w-1.5 flex-none" :class="franjaCategoria(p.categoria_id)" aria-hidden="true"></span>

                            {{-- Si hay foto, va chica a un costado. `object-scale-down`
                                 y NO `object-cover`: la foto entra entera, sin
                                 recortar lo que la hace reconocible. --}}
                            <template x-if="p.imagen">
                                <span class="flex w-16 flex-none items-center justify-center bg-white p-1 dark:bg-white/[0.06]">
                                    <img :src="p.imagen" :alt="p.nombre" loading="lazy"
                                        class="max-h-16 max-w-full object-scale-down" />
                                </span>
                            </template>

                            <span class="flex min-w-0 flex-1 flex-col justify-between gap-1.5 p-3 pr-8">
                                <span class="line-clamp-2 text-theme-sm font-semibold leading-snug text-gray-800 dark:text-white/90"
                                    x-text="p.nombre"></span>
                                <span class="whitespace-nowrap text-base font-bold tabular-nums text-brand-600 dark:text-brand-400"
                                    x-text="'{{ $moneda }} ' + p.precio_estante.toFixed(2)"></span>
                            </span>

                            {{-- Lo que ya va en el pedido, con su cantidad: evita
                                 agregar dos veces lo mismo sin notarlo. --}}
                            <template x-if="enCarrito(p.id)">
                                <span class="absolute right-2 top-2 flex h-6 min-w-6 items-center justify-center rounded-full bg-success-600 px-1.5 text-theme-xs font-bold text-white shadow-theme-sm"
                                    x-text="cantidadTexto(enCarrito(p.id))"></span>
                            </template>
                        </button>
                    </template>

                    <p x-show="!productos.length" class="col-span-full py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                        No hay nada en el menú que coincida con la búsqueda.
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
                <form method="POST" action="{{ route('pos.store') }}" @submit.prevent="confirmarYEnviar($event)" x-ref="carrito" id="pos-formulario"
                    class="flex flex-col rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] xl:sticky xl:top-24 xl:max-h-[calc(100vh-10rem)] xl:overflow-hidden">
                    @csrf
                    {{-- Fuera de `campos`, que se vacía y se rearma al enviar: el
                         número de envío tiene que sobrevivir para que un reenvío
                         del navegador no cobre el pedido dos veces. --}}
                    @unEnvio
                    <div x-ref="campos"></div>

                    {{-- Cabecera baja: cada renglón que ocupa aquí se lo quita a la
                         lista del pedido, que es lo que el cajero repasa. Por eso la
                         caja va junto al título y el nombre, en la misma fila que
                         «comer aquí / para llevar». --}}
                    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="flex min-w-0 items-baseline gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                Pedido
                                <span class="truncate text-theme-xs font-normal text-gray-500 dark:text-gray-400"
                                    title="Turno abierto por {{ $sesion->usuarioApertura?->usuario ?? auth()->user()->usuario }}">{{ $sesion->caja?->nombre }}</span>
                            </h2>

                            <div class="flex flex-none items-center gap-3">
                                <span x-show="carrito.length"
                                    class="rounded-full bg-brand-50 px-3 py-1 text-theme-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-400"
                                    x-text="cantidadTexto(articulos) + (articulos == 1 ? ' artículo' : ' artículos')"></span>
                                <button type="button" x-show="carrito.length" @click="carrito = []"
                                    class="-my-2 flex min-h-11 items-center rounded-lg px-2 text-theme-xs font-medium text-error-600 transition hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">Vaciar</button>
                            </div>
                        </div>
                        {{-- Comer aquí o para llevar: lo dicen el ticket y la cocina,
                             y es lo primero que se pregunta al cliente. Dos botones
                             y no un desplegable: se elige de un toque. Por omisión,
                             comer aquí, que es lo habitual en el local. El nombre
                             para llamarlo va en la misma fila. --}}
                        <div class="mt-2.5 flex flex-wrap gap-2">
                            <div role="group" aria-label="El pedido es" class="inline-flex flex-none rounded-lg border border-gray-300 p-0.5 dark:border-gray-700" data-tipo-pedido>
                                <button type="button" @click="tipo = 'LOCAL'" :aria-pressed="tipo === 'LOCAL'"
                                    :class="tipo === 'LOCAL'
                                        ? 'bg-brand-500 text-white'
                                        : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                                    class="min-h-10 rounded-md px-3 text-theme-sm font-semibold transition">Comer aquí</button>
                                <button type="button" @click="tipo = 'LLEVAR'" :aria-pressed="tipo === 'LLEVAR'"
                                    :class="tipo === 'LLEVAR'
                                        ? 'bg-warning-500 text-white'
                                        : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                                    class="min-h-10 rounded-md px-3 text-theme-sm font-semibold transition">Para llevar</button>
                            </div>
                            <input type="text" x-model="nombrePedido" maxlength="80"
                                aria-label="Nombre para llamarlo (opcional)" placeholder="Nombre (opcional)"
                                class="dark:bg-dark-900 h-11 min-w-[7rem] flex-1 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>
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
                        {{-- Una fila por plato: cantidad, nombre, lo que cuesta y
                             quitar. Antes cada plato ocupaba tres renglones y con
                             tres platos ya había que bajar para ver el pedido
                             entero; así se lee de un vistazo, que es lo que el
                             cajero repasa con el cliente antes de cobrar.

                             El plato recién agregado se ilumina un instante
                             (`recienId`): confirma el toque sin mirar la cuadrícula. --}}
                        <template x-for="(l, i) in carrito" :key="l.producto_id">
                            <div class="px-4 py-2.5 transition-colors duration-700 motion-reduce:transition-none"
                                :class="recienId === l.producto_id ? 'bg-brand-50 dark:bg-brand-500/10' : ''"
                                :data-linea="l.producto_id">
                                <div class="flex items-center gap-3">
                                    {{-- Hay una fila de estas por plato: sin nombre propio, un
                                         lector de pantalla no dice de cuál está hablando. --}}
                                    <div class="flex flex-none items-center">
                                        <button type="button" @click="sumar(i, -1)" :aria-label="`Quitar una porción de ${l.nombre}`"
                                            class="flex h-10 w-10 items-center justify-center rounded-l-lg border border-gray-200 text-lg leading-none text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">−</button>
                                        <input type="number" inputmode="numeric" step="1" min="0"
                                            x-model.number="l.cantidad" @change="normalizar(i)"
                                            :aria-label="`Cantidad de ${l.nombre}`"
                                            class="dark:bg-dark-900 h-10 w-11 border-y border-gray-200 bg-transparent px-1 text-center text-base font-semibold tabular-nums text-gray-800 [appearance:textfield] focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                        <button type="button" @click="sumar(i, 1)" :aria-label="`Agregar una porción de ${l.nombre}`"
                                            class="flex h-10 w-10 items-center justify-center rounded-r-lg border border-gray-200 text-lg leading-none text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">+</button>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                            x-text="l.nombre"></p>
                                        <p class="flex items-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                            <span x-show="l.cantidad > 1" class="tabular-nums"
                                                x-text="'{{ $moneda }} ' + l.precio_estante.toFixed(2) + ' c/u'"></span>
                                            <span x-show="l.cantidad > 1 && !l.conNota && !l.nota" aria-hidden="true">·</span>
                                            {{-- La nota para la cocina: «sin cebolla», «término
                                                 medio». Es lo que el cocinero lee en la pantalla y en
                                                 la comanda. Escondida hasta que se pide, para que un
                                                 pedido sin notas no se alargue. --}}
                                            <button type="button" x-show="!l.conNota && !l.nota"
                                                @click="l.conNota = true; $nextTick(() => $el.closest('[data-linea]').querySelector('[data-nota]')?.focus())"
                                                class="-my-1 py-1 font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                                + Nota
                                            </button>
                                        </p>
                                    </div>

                                    <span class="flex-none text-theme-sm font-semibold tabular-nums text-gray-800 dark:text-white/90"
                                        x-text="'{{ $moneda }} ' + montos.totalLinea(l.precio, l.cantidad, l.afecto, tasa, incluido).toFixed(2)"></span>

                                    <button type="button" @click="quitar(i)" :aria-label="`Quitar ${l.nombre} del pedido`"
                                        class="-mr-2 flex h-10 w-9 flex-none items-center justify-center rounded-lg text-gray-400 transition hover:bg-error-50 hover:text-error-500 dark:hover:bg-error-500/10">
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none">
                                            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </div>

                                <input type="text" x-show="l.conNota || l.nota" x-model="l.nota" maxlength="255" data-nota
                                    :aria-label="`Nota para la cocina: ${l.nombre}`"
                                    placeholder="Nota para la cocina: sin cebolla, término medio…"
                                    class="dark:bg-dark-900 mt-2 h-9 w-full rounded-lg border border-brand-200 bg-brand-50/40 px-3 text-sm font-medium text-brand-800 placeholder:font-normal placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-brand-800 dark:bg-brand-500/10 dark:text-brand-300 dark:placeholder:text-white/30" />
                            </div>
                        </template>

                        {{-- El carrito vacío es el estado más frecuente al empezar
                             una venta: dice qué hacer, en vez de una línea de
                             texto gris en medio de un hueco. --}}
                        <div x-show="!carrito.length" class="flex min-h-40 flex-col items-center justify-center gap-3 px-6 py-10 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10 dark:text-brand-400">
                                {{-- Un plato con sus cubiertos. --}}
                                <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none">
                                    <path d="M12 16.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9ZM4 3v6a1.5 1.5 0 0 0 3 0V3M5.5 9v12M20 3c-1.4 0-2.5 1.8-2.5 4s1.1 4 2.5 4M20 11v10"
                                        stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <p class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Agrega lo primero del pedido</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                búscalo por nombre o código y pulsa <kbd class="tecla">Enter</kbd>
                            </p>
                        </div>
                    </div>

                    {{-- Cierra la zona con scroll: de aquí para abajo el pie
                         (botón de cobrar) queda fuera y siempre a la vista. --}}
                    </div>

                    {{-- Deshabilitado se ve gris, no azul claro: un botón azul
                         que no responde parece un fallo. Y en vez de repetir
                         «Cobrar Bs 0.00» dice qué falta para poder cobrar. --}}
                    <div class="flex-none space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        {{-- El total, fijo al pie junto al botón de cobrar: es la
                             cifra que el cajero le dice al cliente, y antes se iba
                             de la vista al bajar a elegir la forma de pago. El
                             vuelto, al lado, por la misma razón.

                             `aria-live`: el total cambia solo —al agregar un plato o
                             teclear un descuento— y quien usa lector de pantalla
                             tiene que oírlo antes de cobrar. --}}
                        <div class="space-y-1" aria-live="polite" aria-atomic="true" data-total-del-pedido>
                            <div x-show="descuentoValido > 0{{ ($tasaImpuesto > 0 && App\Support\Config::facturacionVisible() && ! $impuestoIncluido) ? ' || true' : '' }}"
                                class="flex justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                                <span>{{ App\Support\Config::facturacionVisible() ? 'Subtotal (base)' : 'Subtotal' }}</span>
                                <span class="tabular-nums" x-text="'{{ $moneda }} ' + subtotal.toFixed(2)"></span>
                            </div>
                            <div x-show="descuentoValido > 0" x-cloak
                                class="flex justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                                <span>Descuento</span>
                                <span class="tabular-nums" x-text="'− {{ $moneda }} ' + descuentoValido.toFixed(2)"></span>
                            </div>
                            @if ($tasaImpuesto > 0 && App\Support\Config::facturacionVisible())
                                <div class="flex justify-between text-theme-xs text-gray-500 dark:text-gray-400" data-impuesto-del-total>
                                    <span>{{ $impuestoIncluido ? 'IVA incluido' : 'Impuesto' }} ({{ rtrim(rtrim(number_format($tasaImpuesto * 100, 2), '0'), '.') }}%)</span>
                                    <span class="tabular-nums" x-text="'{{ $moneda }} ' + impuesto.toFixed(2)"></span>
                                </div>
                            @endif
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-theme-sm font-semibold text-gray-700 dark:text-gray-300">Total</span>
                                <span class="text-title-sm font-bold tabular-nums text-gray-900 dark:text-white"
                                    x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                            </div>
                        </div>

                        {{-- Abre la ventana de cobro; no envía nada. La forma de pago, el
                             vuelto y la confirmación están allí. Deshabilitado se ve
                             gris, no azul claro: un botón azul que no responde parece un
                             fallo, y con el pedido vacío dice qué falta. --}}
                        <button type="button" x-ref="abrirCobro" @click="abrirCobro()" :disabled="!carrito.length || total <= 0"
                            class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 text-base font-semibold text-white transition hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-500 dark:disabled:bg-white/[0.06] dark:disabled:text-gray-500">
                            <template x-if="!carrito.length">
                                <span>Agrega algo del menú para cobrar</span>
                            </template>
                            <template x-if="carrito.length">
                                <span class="flex items-center gap-2">
                                    Cobrar
                                    <kbd class="tecla">F4</kbd>
                                </span>
                            </template>
                        </button>
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

            {{-- La ventana de cobro. Armar el pedido y cobrarlo son dos
                 trabajos: en un mismo panel se peleaban el alto, y la
                 forma de pago quedaba al fondo de una lista que había que
                 bajar. Aquí el pago tiene la pantalla para él solo —con
                 el QR a buen tamaño para que el cliente lo escanee— y el
                 panel del pedido usa todo su alto para los platos.

                 Va FUERA del formulario, igual que el alta de cliente: el
                 panel del pedido es `sticky`, y un `sticky` encierra a sus
                 hijos en su propia capa, así que dentro de él la barra de
                 arriba y el menú lateral quedaban por encima de la ventana y
                 le tapaban el título. El botón de confirmar y los campos donde
                 un Enter debe cobrar se atan al formulario con `form=`. --}}
            <div x-show="cobrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-cobro"
                data-ventana-cobro
                class="fixed inset-0 z-99999 flex items-start justify-center overflow-y-auto overscroll-contain p-4 sm:items-center sm:p-6">
                <div @click="cerrarCobro()" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="cobrando"
                    class="relative flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white dark:bg-gray-900">

                    <div class="flex-none border-b border-gray-100 px-6 pt-5 pb-4 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="titulo-cobro" class="text-lg font-semibold text-gray-800 dark:text-white/90">Cobrar pedido</h2>
                                <p class="truncate text-theme-xs text-gray-500 dark:text-gray-400"
                                    x-text="cantidadTexto(articulos) + (articulos == 1 ? ' artículo' : ' artículos') + ' · ' + (tipo === 'LLEVAR' ? 'Para llevar' : 'Comer aquí') + (nombrePedido.trim() ? ' · ' + nombrePedido.trim() : '')"></p>
                            </div>
                            <button type="button" @click="cerrarCobro()" aria-label="Volver al pedido"
                                class="-mr-2 -mt-1 flex h-11 w-11 flex-none items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/[0.05]">
                                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                    <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>

                        {{-- Lo que se cobra, lo más grande de la ventana: es lo que
                             el cajero le dice al cliente. --}}
                        <div class="mt-3 flex items-baseline justify-between gap-3" aria-live="polite" aria-atomic="true">
                            <span class="text-theme-sm text-gray-500 dark:text-gray-400">Total a cobrar</span>
                            <span class="text-title-md font-bold tabular-nums text-gray-900 dark:text-white"
                                x-text="'{{ $moneda }} ' + total.toFixed(2)" data-total-a-cobrar></span>
                        </div>
                        <p x-show="descuentoValido > 0" x-cloak class="text-right text-theme-xs text-gray-500 dark:text-gray-400"
                            x-text="'Incluye un descuento de {{ $moneda }} ' + descuentoValido.toFixed(2)"></p>

                        {{-- Si el servidor rechazó el cobro, la ventana vuelve a
                             abrirse con lo que ya estaba cargado: el motivo va
                             aquí, donde se está mirando, y no arriba en la página
                             tapado por el fondo de la ventana. --}}
                        @if ($huboError && (session('error') || $errors->any()))
                            <div x-show="huboError" class="mt-3 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400" role="alert">
                                {{ session('error') ?: $errors->first() }}
                            </div>
                        @endif
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            {{-- Pago --}}
            <div class="space-y-4 px-6 py-4">

                {{-- Un QR que el cliente ya pagó y no llegó a venta: se puede
                     usar en esta, así nadie paga dos veces. --}}
                <div x-show="qrLibres.length" x-cloak class="rounded-xl bg-warning-50 px-4 py-3 dark:bg-orange-500/10" data-qr-libres>
                    <p class="text-theme-xs font-medium text-warning-700 dark:text-orange-400">
                        QR ya pagados que no llegaron a una venta:
                    </p>
                    <template x-for="c in qrLibres" :key="c.id">
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <span class="text-theme-sm text-warning-700 dark:text-orange-400"
                                x-text="'#' + c.id + ' · {{ $moneda }} ' + c.monto.toFixed(2)"></span>
                            <button type="button" @click="usarQrPagado(c)"
                                class="min-h-11 rounded-lg border border-warning-300 px-3 text-theme-xs font-medium text-warning-700 hover:bg-warning-100 dark:border-orange-500/40 dark:text-orange-400">
                                Usar en esta venta
                            </button>
                        </div>
                    </template>
                </div>

                {{-- Una tarjeta por forma de pago. Con una sola línea se ve
                     igual que antes; la segunda aparece solo si el cliente
                     parte el pago. --}}
                <template x-for="(pago, i) in pagos" :key="i">
                    <div :class="pagos.length > 1 ? 'rounded-xl border border-gray-200 p-3 dark:border-gray-800' : ''">

                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                                x-text="pagos.length > 1 ? 'Forma de pago ' + (i + 1) : '¿Cómo paga?'"></span>

                            <button type="button" x-show="pagos.length > 1" @click="quitarPago(i)"
                                class="rounded-lg px-2 py-1 text-theme-xs text-gray-400 transition hover:bg-gray-100 hover:text-error-600 dark:hover:bg-white/[0.05]">
                                Quitar
                            </button>
                        </div>

                        {{-- Los tres medios de todos los días, grandes y con ícono;
                             los demás (billetera, transferencia) juntos en «Otro».
                             Antes eran cinco botones de texto largo en dos filas. --}}
                        <div class="grid gap-2" :class="metodosOtros.length ? 'grid-cols-4' : 'grid-cols-3'" data-medios-de-pago>
                            <template x-for="m in metodosPrincipales" :key="m.id">
                                <button type="button" @click="cambiarMetodo(pago, m.id); pago.verOtros = false; enfocarCobro(pago)"
                                    :aria-pressed="pago.metodoId === m.id"
                                    :class="pago.metodoId === m.id
                                        ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400'
                                        : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                                    class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border-2 px-1 py-2 text-theme-xs font-semibold transition">
                                    <span aria-hidden="true" x-html="iconoMetodo(m.codigo)"></span>
                                    <span x-text="cortoMetodo(m)"></span>
                                </button>
                            </template>
                            <button type="button" x-show="metodosOtros.length" @click="pago.verOtros = !pago.verOtros"
                                :aria-expanded="!!pago.verOtros"
                                :class="esOtroMetodo(pago)
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400'
                                    : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                                class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border-2 px-1 py-2 text-theme-xs font-semibold transition">
                                <span aria-hidden="true" x-html="iconoMetodo('OTRO')"></span>
                                <span class="max-w-full truncate" x-text="esOtroMetodo(pago) ? cortoMetodo(metodos.find(m => m.id === pago.metodoId)) : 'Otro'"></span>
                            </button>
                        </div>
                        <div x-show="pago.verOtros" x-cloak class="mt-2 flex flex-wrap gap-2">
                            <template x-for="m in metodosOtros" :key="m.id">
                                <button type="button" @click="cambiarMetodo(pago, m.id); pago.verOtros = false; enfocarCobro(pago)"
                                    :aria-pressed="pago.metodoId === m.id"
                                    :class="pago.metodoId === m.id
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10'"
                                    class="min-h-11 rounded-lg px-4 py-2 text-theme-xs font-medium transition"
                                    x-text="m.nombre"></button>
                            </template>
                        </div>

                        {{-- El importe solo hace falta cuando hay más de una
                             forma: con una sola, cubre el total y punto. --}}
                        <div x-show="pagos.length > 1" class="mt-3">
                            <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                Importe
                                <span x-show="vacio(pago)" class="text-brand-500 dark:text-brand-400">— el resto</span>
                            </label>
                            <input type="number" inputmode="decimal" step="0.01" min="0" x-model="pago.monto" form="pos-formulario"
                                :placeholder="montoDe(pago).toFixed(2)"
                                class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                Cubre <span class="font-medium" x-text="'{{ $moneda }} ' + montoDe(pago).toFixed(2)"></span>.
                                Déjalo vacío para que tome lo que falte.
                            </p>
                        </div>

                        {{-- Con qué billete paga: un toque. «Exacto» cuando no hay
                             vuelto. Tras elegirlo el foco pasa a «Confirmar cobro»,
                             así un Enter cobra en vez de volver a tocar el billete.
                             El campo queda para lo que no está en los botones. --}}
                        <div x-show="esEfectivo(pago)" class="mt-4">
                            <p class="mb-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Recibido</p>
                            <div class="grid grid-cols-5 gap-2">
                                <button type="button" @click="pago.recibido = montoDe(pago); $refs.cobrar.focus()"
                                    :class="Number(pago.recibido) === montoDe(pago)
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:hover:bg-white/10'"
                                    class="min-h-12 rounded-lg px-1 text-theme-sm font-semibold transition">Exacto</button>
                                <template x-for="s in sugerenciasDe(pago)" :key="s">
                                    <button type="button" @click="pago.recibido = s; $refs.cobrar.focus()"
                                        :class="Number(pago.recibido) === s
                                            ? 'bg-brand-500 text-white'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:hover:bg-white/10'"
                                        class="min-h-12 rounded-lg px-1 text-theme-sm font-semibold tabular-nums transition"
                                        x-text="formatoBillete(s)"></button>
                                </template>
                            </div>
                            <input type="number" inputmode="decimal" step="0.01" min="0" x-model.number="pago.recibido"
                                aria-label="Efectivo recibido, otro monto" placeholder="Otro monto" data-recibido form="pos-formulario"
                                class="dark:bg-dark-900 mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm tabular-nums text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </div>

                        <div x-show="!esEfectivo(pago) && !esQr(pago)" class="mt-3">
                            <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                Número de operación
                            </label>
                            <input type="text" x-model="pago.referencia" form="pos-formulario" data-referencia
                                :placeholder="exigeReferencia ? 'El del voucher o comprobante' : 'Opcional'"
                                :aria-invalid="faltaReferencia(pago) ? 'true' : 'false'"
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
                                    <button type="button" @click="generarQr(pago)" data-generar-qr
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
                                        {{-- El banco entrega la imagen ya hecha; el simulador, el
                                             texto que hay que dibujar. --}}
                                        <template x-if="pago.qr.imagen">
                                            <img :src="pago.qr.payload" alt="Código QR para pagar" width="260" height="260"
                                                class="h-[260px] w-[260px] rounded-lg bg-white p-2" />
                                        </template>
                                        <template x-if="!pago.qr.imagen">
                                            <canvas :id="'qr-' + pago.qr.id" class="rounded-lg bg-white p-2"></canvas>
                                        </template>

                                        {{-- El total cambió después de generar el QR: ese QR ya
                                             no sirve, el servidor lo rechazaría. --}}
                                        <p x-show="qrDesfasado(pago)" role="alert"
                                            class="mt-2 rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400"
                                            x-text="'El total cambió: este QR es por {{ $moneda }} ' + pago.qr.monto.toFixed(2) + '. Cancélalo y genera otro por {{ $moneda }} ' + montoDe(pago).toFixed(2) + '.'"></p>

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
                                                class="flex-1 rounded-lg bg-success-500 px-3 py-2 text-theme-xs font-medium text-white transition hover:bg-success-600"
                                                x-text="pago.qr.simulado ? 'Ya me pagó' : 'Verificar pago'">
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
                                        <p x-show="qrDesfasado(pago)" role="alert"
                                            class="mt-2 rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400"
                                            x-text="'El total cambió después de cobrar: el cliente pagó {{ $moneda }} ' + pago.qr.monto.toFixed(2) + ' y ahora son {{ $moneda }} ' + montoDe(pago).toFixed(2) + '. Deja el carrito como estaba o cobra la diferencia aparte.'"></p>
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

            </div>
            {{-- Descuento y cliente, plegados al final: casi ninguna venta
                 de mostrador los usa, y abiertos empujaban la forma de
                 pago —que sí se usa en todas— al fondo del panel. Se
                 abren con un toque, y un descuento aplicado o un cliente
                 elegido los deja abiertos a la fuerza: lo que cambia el
                 cobro nunca queda escondido. --}}
            <div class="border-t border-gray-100 dark:border-gray-800">
                <button type="button" @click="masOpciones = !masOpciones" :aria-expanded="opcionesAbiertas"
                    aria-controls="pos-opciones" data-mas-opciones
                    class="flex min-h-12 w-full items-center justify-between gap-3 px-6 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Descuento y cliente</span>
                    <span class="flex min-w-0 items-center gap-2 text-theme-xs text-gray-500 dark:text-gray-400">
                        <span class="truncate" x-text="resumenOpciones"></span>
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            class="flex-none transition-transform motion-reduce:transition-none" :class="opcionesAbiertas ? 'rotate-180' : ''">
                            <path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </button>

                <div id="pos-opciones" x-show="opcionesAbiertas" x-cloak>

            {{-- Cliente --}}
            <div class="border-t border-gray-100 px-6 py-4 dark:border-gray-800">
                <label for="cliente" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                    Cliente
                </label>
                {{-- La lista trae los primeros {{ $clientesEnLista }} por nombre. Con más,
                     los del final se buscan: antes no había forma de elegirlos. --}}
                @if ($hayMasClientes)
                    <div class="mb-2">
                        <input type="search" x-model="clienteQ" @input.debounce.300ms="buscarCliente()"
                            aria-label="Buscar cliente por nombre o documento" placeholder="Buscar por nombre o documento"
                            data-buscar-cliente
                            class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        <ul x-show="clientesEncontrados.length" x-cloak class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-800">
                            <template x-for="c in clientesEncontrados" :key="c.id">
                                <li>
                                    <button type="button" @click="elegirCliente(c)" x-text="c.etiqueta"
                                        class="block w-full px-3 py-2 text-left text-theme-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]"></button>
                                </li>
                            </template>
                        </ul>
                        <p x-show="clienteQ.trim().length >= 2 && !buscandoCliente && !clientesEncontrados.length" x-cloak
                            class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Ningún cliente coincide.</p>
                    </div>
                @endif
                <select id="cliente" x-model.number="clienteId" x-ref="selectCliente"
                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">{{ $clienteGenerico }} (sin registrar{{ App\Support\Config::facturacionVisible() ? ' — recibo' : '' }})</option>
                    @foreach ($clientes as $c)
                        <option value="{{ $c['id'] }}" data-juridica="{{ $c['juridica'] ? '1' : '0' }}">
                            {{ $c['etiqueta'] }}{{ $c['juridica'] && App\Support\Config::facturacionVisible() ? ' — factura' : '' }}
                        </option>
                    @endforeach
                    {{-- Los que se registran sin salir del mostrador, en esta misma venta. --}}
                    <template x-for="c in clientesNuevos" :key="c.id">
                        <option :value="c.id" x-text="{{ App\Support\Config::facturacionVisible() ? "c.etiqueta + (c.factura ? ' — factura' : '')" : 'c.etiqueta' }}"></option>
                    </template>
                </select>
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    @facturacion Persona jurídica recibe <b>factura</b>; el resto, <b>recibo</b>. @endfacturacion
                    <button type="button" @click="abrirNuevoCliente()"
                        class="-my-2 px-1 py-2 font-medium text-brand-500 dark:text-brand-400 hover:text-brand-600">Registrar cliente</button>
                </p>
            </div>

            {{-- Descuento --}}
            <div class="space-y-2 border-t border-gray-100 px-6 py-4 dark:border-gray-800">
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
                        <input id="descuento" form="pos-formulario" type="number" inputmode="decimal" min="0" x-model.number="descuento"
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

            </div>

                </div>
            </div>

                    </div>

                    {{-- El vuelto y el botón, siempre a la vista al pie de la
                         ventana: es lo último que se mira antes de cobrar. --}}
                    <div class="flex-none space-y-2 border-t border-gray-100 px-6 py-4 dark:border-gray-800">
                        <div x-show="vuelto > 0" x-cloak
                            class="flex items-baseline justify-between rounded-xl bg-success-50 px-4 py-2.5 dark:bg-success-500/10"
                            aria-live="polite" data-vuelto>
                            <span class="text-theme-sm font-medium text-success-700 dark:text-success-500">Vuelto</span>
                            <span class="text-title-sm font-bold tabular-nums text-success-700 dark:text-success-500"
                                x-text="'{{ $moneda }} ' + vuelto.toFixed(2)"></span>
                        </div>

                        {{-- Deshabilitado se ve gris, no azul claro: un botón azul
                             que no responde parece un fallo. Y debajo dice qué
                             falta para poder cobrar. --}}
                        <button type="submit" form="pos-formulario" x-ref="cobrar" :disabled="!puedeCobrar || enviando || verificando"
                            class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 text-base font-semibold text-white transition hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-500 dark:disabled:bg-white/[0.06] dark:disabled:text-gray-500">
                            <template x-if="enviando">
                                <span>Registrando…</span>
                            </template>
                            <template x-if="!enviando && verificando">
                                <span>Comprobando precios…</span>
                            </template>
                            <template x-if="!enviando && !verificando">
                                <span class="flex items-center gap-2">
                                    <span x-text="'Confirmar cobro de {{ $moneda }} ' + total.toFixed(2)"></span>
                                    <kbd class="tecla" x-show="puedeCobrar">Enter</kbd>
                                </span>
                            </template>
                        </button>

                        <p x-show="precioActualizado && !enviando" x-cloak
                            class="text-center text-theme-xs font-medium text-warning-700 dark:text-orange-400">
                            El precio de algo del menú cambió — revisa el total y vuelve a cobrar.
                        </p>
                        <p x-show="!puedeCobrar && !enviando && !precioActualizado && motivoBloqueo"
                            class="text-center text-theme-xs text-gray-500 dark:text-gray-400" x-text="motivoBloqueo"></p>
                    </div>
                </div>
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
                                @facturacion<span class="block text-theme-xs opacity-75">Recibe recibo</span>@endfacturacion
                            </button>
                            <button type="button" @click="nuevoCliente.persona = 'JURIDICA'; nuevoCliente.tipo_documento = 'NIT'"
                                :class="nuevoCliente.persona === 'JURIDICA'
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                                    : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                                class="rounded-xl border-2 px-4 py-3 text-left transition">
                                <span class="block text-sm font-medium">Persona jurídica</span>
                                @facturacion<span class="block text-theme-xs opacity-75">Recibe factura</span>@endfacturacion
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
                                        {{-- Los de `tipos_documento` que valen para una persona natural. --}}
                                        @foreach (App\Models\TipoDocumento::opciones('NATURAL') as $codigo => $nombre)
                                            <option value="{{ $codigo }}">{{ $codigo === 'NIT' && App\Support\Config::facturacionVisible() ? 'NIT (recibe factura)' : $nombre }}</option>
                                        @endforeach
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
                                    <input x-model="nuevoCliente.razon_social" placeholder="Distribuidora Oriente S.R.L."
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        NIT <span class="text-error-600 dark:text-error-400">*</span>
                                    </label>
                                    <input x-model="nuevoCliente.documento" placeholder="1023456027"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                    @facturacion<p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Sin NIT no se puede emitir factura.</p>@endfacturacion
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                        Dirección <span class="text-error-600 dark:text-error-400">*</span>
                                    </label>
                                    <input x-model="nuevoCliente.direccion" placeholder="Av. Cañoto 450, Santa Cruz"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                    @facturacion<p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Obligatoria para la factura: es la dirección fiscal.</p>@endfacturacion
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
                        /* Comer aquí o para llevar, y a nombre de quién (opcional):
                           van al pedido, al ticket y a la cocina. */
                        tipo: 'LOCAL',
                        nombrePedido: '',
                        descuento: 0,
                        descuentoModo: 'monto', // 'monto' o 'porcentaje': cómo se lee `descuento`
                        cobrando: false, // la ventana de cobro, abierta
                        turnoDeFoco: 0, // cancela las búsquedas de foco que quedaron viejas
                        masOpciones: false, // la sección plegada de descuento y cliente
                        recienId: null, // el plato recién agregado, que se ilumina un instante
                        recienTimer: null,
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
                        qrSinVenta: @js($qrSinVenta),
                        huboError: @js($huboError),
                        exigeReferencia: @js(\App\Models\MetodoPago::exigeReferencia()),
                        qrSimulado: {{ $qrSimulado ? 'true' : 'false' }},
                        qrSegundos: {{ $qrSegundosConsulta }},
                        rutaQrCrear: '{{ route('qr.crear') }}',
                        rutaQr: '{{ url('pos/qr') }}',
                        enviando: false,
                        verificando: false,
                        precioActualizado: false,

                        // Alta rápida de cliente desde el mostrador, y los que se
                        // eligen desde el buscador (no venían en la lista).
                        clientesNuevos: [],
                        clientesEnLista: @js($clientes->pluck('id')->values()),
                        clienteQ: '',
                        clientesEncontrados: [],
                        buscandoCliente: false,
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
                        incluido: @js($impuestoIncluido),
                        maxDescuento: {{ $descuentoMaximo }},
                        puedeDescontar: {{ $puedeDescontar ? 'true' : 'false' }},
                        {{-- Lo que entra al cajón admite vuelto: `afecta_caja`, como el arqueo. --}}
                        efectivos: @js($metodosPago->where('afecta_caja', true)->pluck('id')->values()),
                        metodos: @js($metodosPago->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'codigo' => $m->codigo])->values()),

                        restaurarVentaEnCurso() {
                            let guardada = null;

                            try {
                                guardada = JSON.parse(sessionStorage.getItem('pos-venta-en-curso') || 'null');
                                sessionStorage.removeItem('pos-venta-en-curso');
                            } catch (err) {}

                            if (!guardada || !this.huboError) return;

                            this.carrito = guardada.carrito || [];
                            this.clienteId = guardada.clienteId ?? this.clienteId;
                            this.tipo = guardada.tipo || 'LOCAL';
                            this.nombrePedido = guardada.nombrePedido || '';
                            this.descuento = guardada.descuento ?? 0;
                            this.descuentoModo = guardada.descuentoModo || 'monto';

                            if (Array.isArray(guardada.pagos) && guardada.pagos.length) {
                                this.pagos = guardada.pagos;
                                this.pagos.forEach(p => {
                                    if (p.qr && !p.qr.pagado) this.$nextTick(() => { this.pintarQr(p); this.vigilarQr(p); });
                                });
                            }

                            // El servidor rechazó el cobro: se vuelve a la ventana de
                            // cobro, con lo que ya estaba cargado y el motivo a la vista.
                            this.$nextTick(() => this.abrirCobro());
                        },

                        /* La ventana de cobro. Se abre con el pedido armado y se cierra
                           sin perder nada: lo cargado en ella sigue ahí al reabrirla. */
                        abrirCobro() {
                            if (!this.carrito.length || this.total <= 0) return;

                            this.cobrando = true;
                            this.precioActualizado = false;
                            this.enfocarCobro(this.pagos[0]);
                        },

                        cerrarCobro() {
                            if (this.enviando) return;

                            this.cobrando = false;
                            this.$nextTick(() => this.$refs.abrirCobro?.focus());
                        },

                        /* A dónde va el foco al abrir el cobro o elegir un medio: al
                           monto recibido si es efectivo, y si no al botón de confirmar.

                           Reintenta hasta que el destino se vea: `x-show` muestra la
                           ventana en el cuadro de dibujo siguiente, y un `focus()` sobre
                           algo todavía oculto no hace nada —el foco se quedaba en el
                           buscador de atrás—. Si el atrapado de foco se adelanta y pone el
                           suyo, este lo corrige después. */
                        enfocarCobro(pago, intentos = 40) {
                            // Un toque nuevo cancela la búsqueda anterior: si el cajero
                            // toca QR y enseguida Efectivo, la del QR no puede terminar
                            // después y llevarse el foco a «Generar QR».
                            const turno = ++this.turnoDeFoco;
                            const confirmar = () => this.$refs.cobrar?.offsetParent && !this.$refs.cobrar.disabled ? this.$refs.cobrar : null;
                            const visible = (sel) => [...this.$root.querySelectorAll(sel)].find(el => el.offsetParent && !el.disabled);

                            const buscar = () => {
                                if (turno !== this.turnoDeFoco || !this.cobrando) return;

                                // Solo el campo del medio elegido, lo que falta completar:
                                // el monto recibido, generar el QR, el número de operación.
                                // Si no falta nada, confirmar —que deshabilitado no toma el
                                // foco, y entonces se caía al principio de la página—.
                                const destino = !pago ? confirmar()
                                    : this.esEfectivo(pago) ? visible('[data-recibido]')
                                    : this.esQr(pago) ? (visible('[data-generar-qr]') || confirmar())
                                    : (visible('[data-referencia]') || confirmar());

                                if (destino) destino.focus();
                                else if (intentos-- > 0) setTimeout(buscar, 40);
                            };

                            setTimeout(buscar, 40);
                        },

                        /* Los tres medios de todos los días van grandes; el resto, en «Otro». */
                        get metodosPrincipales() {
                            const orden = ['EFECTIVO', 'TARJETA', 'QR'];

                            return this.metodos
                                .filter(m => orden.includes(m.codigo))
                                .sort((a, b) => orden.indexOf(a.codigo) - orden.indexOf(b.codigo));
                        },

                        get metodosOtros() {
                            return this.metodos.filter(m => !['EFECTIVO', 'TARJETA', 'QR'].includes(m.codigo));
                        },

                        esOtroMetodo(pago) {
                            return this.metodosOtros.some(m => m.id === pago.metodoId);
                        },

                        /* El nombre corto para el botón: «Tarjeta débito/crédito» no entra. */
                        cortoMetodo(m) {
                            if (!m) return '';

                            return { EFECTIVO: 'Efectivo', TARJETA: 'Tarjeta', QR: 'QR', BILLETERA: 'Billetera', TRANSFER: 'Transferencia' }[m.codigo] || m.nombre;
                        },

                        iconoMetodo(codigo) {
                            const trazo = 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';

                            return {
                                EFECTIVO: `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2.75" y="6.75" width="18.5" height="10.5" rx="1.5" ${trazo}/><circle cx="12" cy="12" r="2.25" ${trazo}/><path d="M6 9.75v4.5M18 9.75v4.5" ${trazo}/></svg>`,
                                TARJETA: `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2.75" y="5.75" width="18.5" height="12.5" rx="2" ${trazo}/><path d="M2.75 9.75h18.5M6.5 14.5h3" ${trazo}/></svg>`,
                                QR: `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3.75" y="3.75" width="6.5" height="6.5" rx="1" ${trazo}/><rect x="13.75" y="3.75" width="6.5" height="6.5" rx="1" ${trazo}/><rect x="3.75" y="13.75" width="6.5" height="6.5" rx="1" ${trazo}/><path d="M13.75 13.75h2.5v2.5M20.25 13.75v6.5h-6.5v-2.5" ${trazo}/></svg>`,
                            }[codigo] || `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="6" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="18" cy="12" r="1.5" fill="currentColor"/></svg>`;
                        },

                        /* Los billetes, sin decimales cuando no los tienen: «50», no «50.00». */
                        formatoBillete(s) {
                            return Number.isInteger(s) ? String(s) : s.toFixed(2);
                        },

                        async cargar() {
                            const url = new URL('{{ route('pos.productos') }}', window.location.origin);
                            url.searchParams.set('q', this.q);
                            if (this.categoria) url.searchParams.set('categoria', this.categoria);

                            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            this.productos = await respuesta.json();
                        },

                        async buscarCliente() {
                            const texto = this.clienteQ.trim();

                            if (texto.length < 2) {
                                this.clientesEncontrados = [];
                                return;
                            }

                            this.buscandoCliente = true;

                            try {
                                const url = new URL('{{ route('clientes.buscar') }}', window.location.origin);
                                url.searchParams.set('q', texto);
                                const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                                // Si se siguió tecleando, esta respuesta ya es vieja.
                                if (texto === this.clienteQ.trim()) {
                                    this.clientesEncontrados = respuesta.ok ? await respuesta.json() : [];
                                }
                            } catch (e) {
                                this.clientesEncontrados = [];
                            } finally {
                                this.buscandoCliente = false;
                            }
                        },

                        elegirCliente(c) {
                            const yaEsta = this.clientesEnLista.includes(c.id) || this.clientesNuevos.some(n => n.id === c.id);
                            if (!yaEsta) this.clientesNuevos.push(c);

                            // Después de que Alpine dibuje la opción: si no, el
                            // select no tiene todavía a quién seleccionar.
                            this.$nextTick(() => { this.clienteId = c.id; });
                            this.clienteQ = '';
                            this.clientesEncontrados = [];
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
                         * Enter sobre lo tecleado: el código exacto, o el primero.
                         *
                         * La búsqueda tiene 250 ms de espera antes de consultar al
                         * servidor, y quien teclea un código corto y remata con Enter
                         * llega antes: cuando llega el Enter, la lista en pantalla
                         * todavía es la anterior. Si acá se resolviera contra esa
                         * lista, pedir un código que no estuviera en ella
                         * agregaría EL PRIMERO DE LA PANTALLA, en silencio, y el
                         * cajero cobraría otra cosa. Con catorce platos no se
                         * nota porque están todos en memoria; con un menú de
                         * verdad —el servidor manda de a 24— pasaría a diario.
                         *
                         * Por eso primero se consulta y recién después se decide.
                         * Cuesta un viaje al servidor por Enter; cobrar mal cuesta
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

                                /* Si mientras se consultaba se teclearon más letras,
                                   esta respuesta ya no corresponde: la manda la siguiente. */
                                if (this.q.trim() !== texto) {
                                    return;
                                }

                                const exacto = this.productos.find(p => p.codigo === texto);

                                /* Solo se cae en «el primero» cuando lo tecleado NO
                                   parece un código: escribir «papaya» y pulsar Enter
                                   sigue funcionando. Un código sin coincidencia exacta
                                   se avisa, no se adivina.

                                   La regla es estrecha a propósito: el formato del
                                   código interno, «P-1001». Una primera versión aceptaba
                                   cualquier cosa alfanumérica de seis o más y rompió la
                                   búsqueda por nombre: «papaya» pasaba por código. */
                                const pareceCodigo = /^[A-Za-z]{1,4}-\d+$/.test(texto);
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
                            const linea = this.carrito.find(l => l.producto_id === p.id);

                            if (linea) {
                                linea.cantidad = Math.round((linea.cantidad + 1) * 1000) / 1000;
                            } else {
                                this.carrito.push({
                                    producto_id: p.id,
                                    nombre: p.nombre,
                                    precio: p.precio,
                                    precio_estante: p.precio_estante,
                                    afecto: p.afecto,
                                    cantidad: 1,
                                    nota: '',
                                    conNota: false,
                                });
                            }

                            this.marcarRecien(p.id);
                        },

                        /* Ilumina un instante la línea del plato que se acaba de
                           tocar y la trae a la vista si el pedido es largo: el
                           cajero confirma el toque sin buscar en la lista. Va en
                           el estado del componente y no en la línea, para que no
                           viaje al guardar la venta en curso. */
                        marcarRecien(id) {
                            this.recienId = id;
                            clearTimeout(this.recienTimer);
                            this.recienTimer = setTimeout(() => { this.recienId = null; }, 900);

                            this.$nextTick(() => {
                                const quieto = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
                                this.$refs.carrito?.querySelector(`[data-linea="${id}"]`)
                                    ?.scrollIntoView({ block: 'nearest', behavior: quieto ? 'auto' : 'smooth' });
                            });
                        },

                        /* De a una porción: en un restaurante nada se vende
                           partido, así que la cantidad es siempre entera. Antes lo
                           decidía la unidad de medida. */
                        sumar(i, delta) {
                            const l = this.carrito[i];
                            const nueva = l.cantidad + delta;

                            if (nueva <= 0) return this.quitar(i);

                            l.cantidad = nueva;
                        },

                        normalizar(i) {
                            const l = this.carrito[i];
                            const c = Math.round(Number(l.cantidad) || 0);

                            if (c <= 0) return this.quitar(i);

                            l.cantidad = c;
                        },

                        quitar(i) {
                            this.carrito.splice(i, 1);
                        },

                        cantidadTexto(n) {
                            return Number(n).toFixed(3).replace(/\.?0+$/, '');
                        },

                        /* Cuánto de esto va ya en el carrito, o 0. */
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

                        /* Color de la categoría: la franja de la tarjeta y el punto
                           de su ficha de filtro usan el mismo, así se aprende qué
                           color es cada cosa sin leer. Repartido por id; sin
                           categoría, gris. Tonos bien separados entre sí —naranja
                           y ámbar juntos no se distinguen de un vistazo—, y ni el
                           azul de la marca (aquí dice «seleccionado») ni el rojo
                           (dice «error»). Clases completas y literales: Tailwind
                           no ve las que se arman con variables. */
                        franjaCategoria(id) {
                            const colores = @js(\App\Support\ColorCategoria::SOLIDOS);

                            return id ? colores[id % colores.length] : 'bg-gray-300 dark:bg-gray-700';
                        },

                        /* La sección de descuento y cliente se abre a mano, pero un
                           descuento aplicado o un cliente elegido la mantienen
                           abierta: lo que cambia el cobro no puede quedar escondido. */
                        get opcionesAbiertas() {
                            return this.masOpciones || this.descuentoValido > 0 || !!this.clienteId;
                        },

                        /* Lo que hay dentro, dicho en la propia barra plegada. */
                        get resumenOpciones() {
                            const partes = [];

                            if (this.descuentoValido > 0) partes.push('− {{ $moneda }} ' + this.descuentoValido.toFixed(2));
                            if (this.clienteId) {
                                const nombre = this.$refs.selectCliente?.selectedOptions?.[0]?.textContent?.trim();
                                partes.push(nombre || 'Cliente elegido');
                            }

                            return partes.join(' · ') || 'Opcional';
                        },

                        /* Lo que se descuenta: la suma de las líneas a su precio. Con el
                           impuesto encima es la base; con el impuesto incluido, lo
                           que paga el cliente por lo consumido. */
                        get subtotal() {
                            return montos.sumar(this.carrito.map(l => montos.importeLinea(l.precio, l.cantidad)));
                        },

                        get impuesto() {
                            /* Se redondea POR LÍNEA, igual que la columna generada
                               `impuesto_linea`: sumar sin redondear daría otro total. */
                            const bruto = montos.sumar(this.carrito.map(l => {
                                if (!l.afecto) return 0;
                                const importe = montos.importeLinea(l.precio, l.cantidad);
                                return this.incluido ? montos.impuestoIncluido(importe, this.tasa) : montos.impuestoDe(importe, this.tasa);
                            }));
                            /* El descuento de cabecera se prorratea, igual que en sp_recalcular_venta. */
                            return montos.impuestoConDescuento(bruto, this.subtotal, this.descuentoValido);
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

                            // Al centavo: así viaja al servidor (toFixed(2)), y con
                            // 1,555 la pantalla y la venta daban totales distintos.
                            return Math.round(Math.min(d, this.subtotal) * 100) / 100;
                        },

                        descontarPorcentaje(porcentaje) {
                            this.descuentoModo = 'porcentaje';
                            this.descuento = porcentaje;
                        },

                        get total() {
                            /* Con el impuesto incluido ya está dentro del precio. */
                            return this.incluido
                                ? this.redondear(this.subtotal - this.descuentoValido)
                                : this.redondear(this.subtotal - this.descuentoValido + this.impuesto);
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

                        /* Tarjeta, billetera o transferencia sin el número del voucher. */
                        faltaReferencia(pago) {
                            return this.exigeReferencia && !this.esEfectivo(pago) && !this.esQr(pago)
                                && !String(pago.referencia || '').trim();
                        },

                        /* Una línea de QR solo sirve si su cobro está pagado. */
                        qrPendiente(pago) {
                            return this.esQr(pago) && !(pago.qr && pago.qr.pagado);
                        },

                        /* El QR se generó por un importe y el carrito cambió después. */
                        qrDesfasado(pago) {
                            return this.esQr(pago) && !!pago.qr && Math.abs(Number(pago.qr.monto) - this.montoDe(pago)) > 0.001;
                        },

                        /* Cambiar de forma de pago deja de lado el QR: si todavía no se
                           pagó, se anula en el banco para que no quede cobrable. */
                        cambiarMetodo(pago, metodoId) {
                            if (pago.metodoId === metodoId) return;
                            if (pago.qr && !pago.qr.pagado) this.anularQr(pago);
                            if (pago.qr && pago.qr.pagado && !this.metodosQr.includes(metodoId)) {
                                pago.qrError = 'Ese QR ya está pagado: el dinero está en el banco. Déjalo como pago por QR.';
                                return;
                            }
                            pago.metodoId = metodoId;
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
                            if (pago.qr.imagen) return;
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

                        /* Cancela el QR en el banco. Si el banco dice que ya se pagó,
                           el QR se queda —pagado— para usarlo: antes se borraba igual
                           y el cajero generaba otro que el cliente volvía a pagar. */
                        async anularQr(pago) {
                            if (!pago.qr) return true;

                            try {
                                const r = await fetch(this.rutaQr + '/' + pago.qr.id + '/anular', {
                                    method: 'POST',
                                    headers: this.cabecera,
                                });
                                const datos = await r.json().catch(() => ({}));

                                if (!r.ok) {
                                    const consulta = await fetch(this.rutaQr + '/' + pago.qr.id, { headers: { 'Accept': 'application/json' } });
                                    if (consulta.ok) pago.qr = await consulta.json();
                                    pago.qrError = datos.error ?? 'No se pudo cancelar el QR.';
                                    return false;
                                }
                            } catch (e) {
                                pago.qrError = 'No se pudo cancelar el QR: revisa la conexión y vuelve a intentar.';
                                return false;
                            }

                            pago.qr = null;
                            pago.qrError = '';
                            return true;
                        },

                        /* Los QR pagados sin venta que todavía no se están usando. */
                        get qrLibres() {
                            const enUso = this.pagos.filter(p => p.qr).map(p => p.qr.id);
                            return this.qrSinVenta.filter(c => !enUso.includes(c.id));
                        },

                        usarQrPagado(cobro) {
                            const metodoQr = this.metodosQr[0];
                            if (!metodoQr) return;

                            // Si la única forma de pago está vacía, esa pasa a ser el QR.
                            const libre = this.pagos.length === 1 && !this.pagos[0].qr && this.vacio(this.pagos[0])
                                ? this.pagos[0] : null;

                            if (libre && this.total <= cobro.monto + 0.001) {
                                libre.metodoId = metodoQr;
                                libre.qr = cobro;
                                libre.qrError = '';
                                return;
                            }

                            /* La forma de pago que va «por el resto» se deja así: el QR
                               entra con su importe fijo y ella cubre la diferencia.
                               Fijarla en el total dejaba lo pagado por encima del total
                               y el botón de cobrar apagado. */
                            this.pagos.push({
                                metodoId: metodoQr,
                                monto: cobro.monto.toFixed(2),
                                recibido: null,
                                referencia: '',
                                qr: cobro,
                                qrCargando: false,
                                qrError: '',
                            });
                        },

                        async quitarPago(indice) {
                            if (this.pagos.length <= 1) return;

                            const pago = this.pagos[indice];

                            // Un QR pagado no se descarta en silencio: ese dinero ya está en el banco.
                            if (pago.qr && pago.qr.pagado) {
                                pago.qrError = 'Ese QR ya está pagado: úsalo en la venta o avisa al administrador.';
                                return;
                            }

                            // Uno pendiente se cancela en el banco antes de quitarlo.
                            if (pago.qr && !(await this.anularQr(pago))) return;

                            this.pagos.splice(this.pagos.indexOf(pago), 1);

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

                            // Sin los que dejarían un vuelto que el servidor rechaza

                            // (igual o mayor al billete más grande: `Ventas::billeteMayor`).

                            billetes.filter(b => b >= t && this.redondear(b - t) < this.billeteMayor).forEach(b => opciones.add(b));

                            return [...opciones].sort((a, b) => a - b).slice(0, 4);
                        },

                        /* Un efectivo al que no se le puso «recibido» se toma como
                           justo: el cajero no siempre lo teclea si le dieron exacto. */
                        /* El mismo tope que el servidor: un vuelto igual o mayor al billete
                           más grande es casi siempre un cero de más al teclear. */
                        billeteMayor: {{ \App\Services\Ventas::billeteMayor() }},

                        vueltoExcesivo(pago) {
                            return this.esEfectivo(pago)
                                && pago.recibido !== null && pago.recibido !== ''
                                && this.redondear(Number(pago.recibido) - this.montoDe(pago)) >= this.billeteMayor;
                        },

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
                            if (!this.carrito.length || this.total <= 0) return false;
                            if (this.excedeDescuento && !this.puedeDescontar) return false;
                            if (!this.pagoCubierto) return false;
                            if (this.pagos.some(p => this.efectivoCorto(p))) return false;
                            if (this.pagos.some(p => this.vueltoExcesivo(p))) return false;
                            if (this.pagos.some(p => this.qrPendiente(p))) return false;
                            if (this.pagos.some(p => this.qrDesfasado(p))) return false;
                            if (this.pagos.some(p => this.faltaReferencia(p))) return false;

                            return true;
                        },

                        get motivoBloqueo() {
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
                            if (this.pagos.some(p => this.vueltoExcesivo(p))) return 'El efectivo recibido deja un vuelto demasiado grande: revisa lo que tecleaste.';
                            if (this.pagos.some(p => this.qrDesfasado(p))) return 'El total cambió después de generar el QR.';
                            if (this.pagos.some(p => this.qrPendiente(p))) return 'Falta que se confirme el pago por QR.';
                            if (this.pagos.some(p => this.faltaReferencia(p))) return 'Falta el número de operación del voucher.';
                            return '';
                        },

                        redondear(n) {
                            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
                        },

                        /* El carrito guarda el precio de cada línea desde que se agregó.
                           Si alguien edita el menú mientras el cajero todavía no
                           cobra, la pantalla queda con un total/vuelto viejo aunque el
                           servidor siempre cobre el precio del menú actual — y si el
                           precio bajó, el backend no tiene forma de notarlo (el efectivo
                           recibido igual alcanza), así que la venta se registraría sin
                           error con un vuelto real distinto al que ya se le dio al
                           cliente mirando la pantalla. Por eso se refresca el carrito
                           contra el menú justo antes de enviar, y si algo cambió se
                           detiene: el cajero ve el total correcto y confirma de nuevo. */
                        async confirmarYEnviar(e) {
                            // Un Enter en un campo del pedido (el nombre, por ejemplo)
                            // envía el formulario: sin la ventana abierta, eso abre el
                            // cobro, no cobra a ciegas.
                            if (!this.cobrando) {
                                this.abrirCobro();
                                return;
                            }

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

                        /* Compara los precios del carrito contra el menú. Devuelve
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

                                if (p.afecto !== undefined && p.afecto !== l.afecto) {
                                    l.afecto = p.afecto;
                                    cambio = true;
                                }
                            });

                            return cambio;
                        },

                        /* Se arma el formulario en el momento de enviar: el carrito vive en Alpine. */
                        preparar(e) {
                            this.enviando = true;

                            /* Si el servidor rechaza la venta (vuelto, precio), la
                               página vuelve vacía: se guarda lo armado para restaurarlo,
                               QR pagado incluido. */
                            try {
                                sessionStorage.setItem('pos-venta-en-curso', JSON.stringify({
                                    carrito: this.carrito,
                                    pagos: this.pagos.map(p => ({ ...p, qrCargando: false })),
                                    clienteId: this.clienteId,
                                    descuento: this.descuento,
                                    descuentoModo: this.descuentoModo,
                                    tipo: this.tipo,
                                    nombrePedido: this.nombrePedido,
                                }));
                            } catch (err) {}

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
                                if (l.nota && l.nota.trim()) oculto(`lineas[${i}][nota]`, l.nota.trim());
                            });

                            oculto('tipo', this.tipo);
                            if (this.nombrePedido.trim()) oculto('nombre_cliente', this.nombrePedido.trim());

                            /* De la línea que va «por el resto» NO se manda el importe:
                               lo calcula el servidor sobre su propio total, así un
                               céntimo de diferencia en el redondeo del navegador no
                               puede tumbar la venta. Las demás sí lo llevan, porque
                               son un reparto que decidió el cajero. */
                            /* El total que se le cantó al cliente: el servidor rechaza la
                               venta si no coincide con el suyo. */
                            oculto('total_esperado', this.total.toFixed(2));

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
                                if (this.esQr(p) && p.qr && p.qr.pagado) {
                                    oculto(`pagos[${i}][cobro_qr_id]`, p.qr.id);
                                }
                            });

                            if (this.clienteId) oculto('cliente_id', this.clienteId);
                            if (this.descuentoValido > 0) oculto('descuento', this.descuentoValido.toFixed(2));
                        },

                        /* F2 vuelve al buscador (cerrando el cobro si estaba abierto),
                           F4 abre el cobro y, ya abierto, cobra; Esc lo cierra. El
                           alta de cliente se cierra primero: va encima del cobro. */
                        atajos(e) {
                            if (e.key === 'F2') {
                                e.preventDefault();
                                if (this.cobrando) this.cerrarCobro();
                                this.$nextTick(() => {
                                    this.$refs.buscador.focus();
                                    this.$refs.buscador.select();
                                });
                            }

                            if (e.key === 'F4') {
                                if (!this.cobrando && this.carrito.length) {
                                    e.preventDefault();
                                    this.abrirCobro();
                                } else if (this.cobrando && this.puedeCobrar) {
                                    e.preventDefault();
                                    this.$refs.cobrar.click();
                                }
                            }

                            if (e.key === 'Escape' && this.cobrando && !this.nuevoClienteAbierto) {
                                e.preventDefault();
                                this.cerrarCobro();
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endunless
@endsection
