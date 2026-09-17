@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    {{-- Un solo estado para toda la pantalla: `sel` guarda el producto sobre el
         que se va a operar, y los dos modales leen de ahí. Así la fila solo
         tiene que decir cuál es, y no se repite un formulario por producto. --}}
    <div class="space-y-6" x-data="{
        ingresando: false,
        ajustando: false,
        sel: {
            id: null, nombre: '', codigo: '', stock: 0, unidad: '', unidadNombre: '', paso: 1,
            proveedor: '', compra: 0,
            /* Empaque: `contenido` en 0 significa «este producto no viene en
               caja», y es lo que decide qué casillas muestra el modal. */
            contenido: 0, empaque: '', desglose: null,
        },
        /* Lo que se escribió en «Costo», y a cuánto equivale por unidad: es lo
           único que decide si tiene sentido ofrecer actualizar el producto. */
        costo: '',
        costoPor: 'UNIDAD',
        actualizarCosto: true,
        get costoEscrito() {
            return this.costo !== '' && this.costo !== null && Number(this.costo) >= 0;
        },
        get costoNuevo() {
            const c = Number(this.costo) || 0;
            return this.costoPor === 'EMPAQUE' && this.sel.contenido > 0
                ? Math.round((c / this.sel.contenido) * 100) / 100
                : c;
        },
        abrir(modal, producto) {
            this.sel = producto;
            /* El costo arranca en el del producto: lo más común es que la
               compra llegue al mismo precio de siempre. */
            this.costo = producto.compra;
            this.costoPor = 'UNIDAD';
            this.actualizarCosto = true;
            this[modal] = true;
        },
        get diferencia() {
            return Math.round((this.contado - this.sel.stock) * 1000) / 1000;
        },
        contado: 0,
    }">

        {{-- Cifras de cabecera --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @php
                // Clases literales: Tailwind rastrea el texto de la plantilla y
                // un `text-{{ $color }}-500` nunca se generaría.
                $tarjetas = [
                    ['Productos en catálogo', number_format($resumen['total']), 'text-gray-800 dark:text-white/90', null],
                    ['Valor del inventario', Config::importe($resumen['valor']), 'text-success-700 dark:text-success-500', 'al precio de compra'],
                    ['Bajo el mínimo', number_format($resumen['bajo_minimo']), 'text-warning-700 dark:text-orange-400', 'conviene reponer'],
                    ['Agotados', number_format($resumen['agotados']), 'text-error-600 dark:text-error-400', 'sin stock'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $etiqueta }}
                    </p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Filtros --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('inventario.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar" help="Nombre, código interno o código de barras.">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Arroz, P-0001 o 7790001000019" autofocus />
                    </x-form.campo>
                </div>

                <x-form.campo label="Categoría" for="categoria">
                    <x-form.select id="categoria" name="categoria" :value="$filtros['categoria']"
                        placeholder="Todas" :opciones="$categorias" />
                </x-form.campo>

                <x-form.campo label="Proveedor" for="proveedor">
                    <x-form.select id="proveedor" name="proveedor" :value="$filtros['proveedor']"
                        placeholder="Todos" :opciones="$proveedores" />
                </x-form.campo>

                <x-form.campo label="Stock" for="stock">
                    <x-form.select id="stock" name="stock" :value="$filtros['stock']" placeholder="Cualquiera"
                        :opciones="['BAJO' => 'Bajo el mínimo', 'AGOTADO' => 'Agotados']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('inventario.index')">Limpiar</x-ui.button>
                    <x-ui.button variant="outline" size="sm"
                        :href="route('inventario.index', ['stock' => 'BAJO'])">Solo lo que falta</x-ui.button>
                    <x-ui.button variant="outline" size="sm" class="ml-auto"
                        :href="route('inventario.movimientos')">Ver movimientos</x-ui.button>
                </div>
            </form>
        </div>

        {{-- Existencias --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="nombre" defecto>Producto</x-tabla.th>
                            {{-- En el teléfono sobreviven producto, stock y acciones:
                                 lo justo para reconocerlo y cargarlo. --}}
                            <x-tabla.th clave="categoria" class="hidden md:table-cell">Categoría</x-tabla.th>
                            <x-tabla.th clave="stock" inicial="asc" derecha>Stock</x-tabla.th>
                            <x-tabla.th clave="minimo" derecha class="hidden lg:table-cell">Mínimo</x-tabla.th>
                            <x-tabla.th clave="faltante" inicial="desc" derecha class="hidden sm:table-cell">Falta</x-tabla.th>
                            <x-tabla.th clave="valor" inicial="desc" derecha class="hidden lg:table-cell">Valor</x-tabla.th>
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($productos as $producto)
                            @php
                                $unidad = $producto->unidadMedida;
                                $falta = round((float) $producto->stock_minimo - (float) $producto->stock_actual, 3);
                                $datos = [
                                    'id' => $producto->id,
                                    'nombre' => $producto->nombre,
                                    'codigo' => $producto->codigo,
                                    'stock' => (float) $producto->stock_actual,
                                    'unidad' => $unidad?->codigo,
                                    'unidadNombre' => mb_strtolower($unidad?->nombre ?? 'unidad'),
                                    'paso' => $unidad?->permite_decimal ? 0.001 : 1,
                                    'proveedor' => $producto->proveedor_id,
                                    'compra' => (float) $producto->precio_compra,
                                    'contenido' => (float) $producto->contenido_empaque,
                                    'empaque' => mb_strtolower($producto->nombre_empaque ?? ''),
                                    'desglose' => $producto->stock_desglosado,
                                ];
                            @endphp

                            {{-- El producto viaja en el `x-data` de la fila y no en el
                                 `@click` del botón: dentro del atributo de un
                                 componente Blade, `@js(...)` no se compila y llegaría
                                 como texto literal al navegador. --}}
                            <tr x-data="{ producto: {{ Illuminate\Support\Js::from($datos) }} }"
                                class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.foto-producto :producto="$producto" size="sm" />
                                        <div class="min-w-0">
                                            <a href="{{ route('productos.show', $producto) }}"
                                                class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                                {{ $producto->nombre }}
                                            </a>
                                            <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $producto->codigo }}
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $producto->categoria?->nombre }}
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <span
                                        class="font-medium text-theme-sm {{ $producto->sin_stock ? 'text-error-600 dark:text-error-400' : ($producto->bajo_minimo ? 'text-warning-700 dark:text-orange-400' : 'text-gray-800 dark:text-white/90') }}">
                                        {{ Config::cantidad($producto->stock_actual) }} {{ $unidad?->codigo }}
                                    </span>
                                    {{-- Cuántas cajas es eso: el número que se puede
                                         contrastar mirando el depósito. Va antes del
                                         estado y no en su lugar — justo cuando queda
                                         poco es cuando más se quiere saber si es
                                         media caja o tres. --}}
                                    @if ($producto->stock_desglosado)
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $producto->stock_desglosado }}
                                        </span>
                                    @endif
                                    @if ($producto->sin_stock)
                                        <span class="block text-theme-xs text-error-600 dark:text-error-400">agotado</span>
                                    @elseif ($producto->bajo_minimo)
                                        <span class="block text-theme-xs text-warning-700 dark:text-orange-400">bajo el mínimo</span>
                                    @endif
                                </td>

                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ Config::cantidad($producto->stock_minimo) }}
                                </td>

                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm sm:table-cell">
                                    @if ($falta > 0)
                                        <span class="font-medium text-warning-700 dark:text-orange-400">
                                            {{ Config::cantidad($falta) }}
                                        </span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>

                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ Config::importe($producto->valor_inventario) }}
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center justify-end gap-1">
                                        @puede('inventario.ingresar')
                                            <x-ui.button size="sm" type="button"
                                                x-on:click="abrir('ingresando', producto)">Ingresar</x-ui.button>
                                        @endpuede

                                        @puede('inventario.ajustar')
                                            <x-ui.button size="sm" variant="outline" type="button"
                                                x-on:click="abrir('ajustando', producto); contado = producto.stock">Ajustar</x-ui.button>
                                        @endpuede

                                        <a href="{{ route('inventario.movimientos', ['producto' => $producto->id]) }}"
                                            title="Movimientos de este producto"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h10.5" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay productos con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$productos" />
        </div>

        {{-- Lo último que se cargó: sirve para confirmar que lo de hoy ya entró --}}
        @if ($ultimos->isNotEmpty())
            <x-common.component-card title="Últimas entradas y ajustes"
                desc="Solo los movimientos que se hacen desde aquí. Las salidas por venta están en el listado completo.">
                <div class="max-w-full overflow-x-auto overscroll-x-contain">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <x-tabla.th>Fecha</x-tabla.th>
                                <x-tabla.th>Producto</x-tabla.th>
                                <x-tabla.th>Movimiento</x-tabla.th>
                                <x-tabla.th derecha>Cantidad</x-tabla.th>
                                <x-tabla.th derecha class="hidden sm:table-cell">Stock</x-tabla.th>
                                <x-tabla.th class="hidden md:table-cell">Responsable</x-tabla.th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($ultimos as $movimiento)
                                <tr>
                                    <td class="px-5 py-3 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->fecha?->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-5 py-3 text-theme-sm">
                                        <a href="{{ route('productos.show', $movimiento->producto_id) }}"
                                            class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">
                                            {{ $movimiento->producto?->nombre }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->etiqueta_origen }}
                                        @if ($movimiento->motivo_visible)
                                            <span class="block text-theme-xs">{{ $movimiento->motivo_visible }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-theme-sm">
                                        <span
                                            class="font-medium {{ $movimiento->variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                            {{ $movimiento->variacion > 0 ? '+' : '' }}{{ Config::cantidad($movimiento->variacion) }}
                                        </span>
                                    </td>
                                    <td class="hidden px-5 py-3 text-right whitespace-nowrap text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                        {{ Config::cantidad($movimiento->stock_anterior) }} →
                                        {{ Config::cantidad($movimiento->stock_resultante) }}
                                    </td>
                                    <td class="hidden px-5 py-3 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                        {{ $movimiento->responsable_visible }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-common.component-card>
        @endif

        {{-- ------------------------------------------------------ modales --}}

        @puede('inventario.ingresar')
            <div x-show="ingresando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-ingreso"
                @keydown.escape.window="ingresando = false"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="ingresando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="ingresando"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-ingreso" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Ingresar mercadería
                    </h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Entrada de <b x-text="sel.nombre"></b>. Stock actual:
                        <span x-text="sel.stock"></span> <span x-text="sel.unidad"></span><span
                            x-show="sel.desglose" x-cloak> (<span x-text="sel.desglose"></span>)</span>.
                    </p>

                    <form method="POST" action="{{ route('inventario.ingreso') }}" class="space-y-5">
                        @csrf
                        @unEnvio
                        <input type="hidden" name="producto_id" :value="sel.id" />

                        {{-- Las expresiones apuntan a `sel`, que es el producto de la
                             fila desde la que se abrió el modal: el mismo formulario
                             sirve para uno que viene en cajas y para uno a granel. --}}
                        <x-form.cantidad-empaque prefijo="ing_" hay-empaque="sel.contenido > 0"
                            contenido="sel.contenido" empaque="sel.empaque" unidad="sel.unidad"
                            paso="sel.paso" />

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <x-form.campo label="Costo" for="ing_costo" name="costo_unitario" class="sm:col-span-2"
                                help="Opcional. Lo que costó esta compra, sin impuesto.">
                                {{-- El ancho va en un envoltorio y no en el propio
                                     control: los componentes de formulario traen
                                     `w-full`, y una clase de ancho puesta encima
                                     pierde por orden de la hoja de estilos. --}}
                                <div class="flex gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-form.input id="ing_costo" name="costo_unitario" type="number"
                                            step="0.01" min="0" x-model="costo" />
                                    </div>

                                    {{-- La factura del proveedor está en cajas. Se acepta
                                         así y el sistema divide. --}}
                                    <div class="w-36 shrink-0" x-show="sel.contenido > 0" x-cloak>
                                        <x-form.select name="costo_por" x-model="costoPor">
                                            <option value="UNIDAD" x-text="'por ' + sel.unidadNombre"></option>
                                            <option value="EMPAQUE" x-text="'por ' + sel.empaque"></option>
                                        </x-form.select>
                                    </div>
                                </div>

                                {{-- Sin esto, el costo se quedaba solo en el kardex y
                                     el producto seguía con el precio del alta: el
                                     margen y el valor del inventario mentían en
                                     silencio desde la primera subida del proveedor. --}}
                                <div x-show="costoEscrito && costoNuevo !== sel.compra" x-cloak class="mt-3">
                                    <x-form.check name="actualizar_costo" :checked="true" model="actualizarCosto">
                                        Actualizar el costo de este producto
                                        (<span x-text="sel.compra.toFixed(2)"></span> →
                                        <b x-text="costoNuevo.toFixed(2)"></b>)
                                    </x-form.check>
                                </div>
                            </x-form.campo>

                            <x-form.campo label="Proveedor" for="ing_proveedor" name="proveedor_id">
                                <x-form.select id="ing_proveedor" name="proveedor_id" placeholder="Sin especificar"
                                    :opciones="$proveedores" x-bind:value="sel.proveedor" />
                            </x-form.campo>

                            <x-form.campo label="Guía o factura" for="ing_documento" name="documento_externo"
                                help="El documento con el que llegó la mercadería.">
                                <x-form.input id="ing_documento" name="documento_externo" placeholder="F001-00123" />
                            </x-form.campo>

                            <x-form.campo label="Observación" for="ing_motivo" name="motivo">
                                <x-form.input id="ing_motivo" name="motivo" placeholder="Opcional" />
                            </x-form.campo>

                            {{-- Solo para lo que se lleva por lotes: sin fecha, la tanda
                                 queda fuera del control de vencimientos. --}}
                            <template x-if="sel.controlaVencimiento">
                                <div class="grid grid-cols-1 gap-5 sm:col-span-2 sm:grid-cols-2">
                                    <x-form.campo label="Vence el" for="ing_vence" name="vence" required
                                        help="La fecha de la tanda que llegó.">
                                        <x-form.input id="ing_vence" name="vence" type="date" />
                                    </x-form.campo>

                                    <x-form.campo label="Lote del proveedor" for="ing_lote" name="lote">
                                        <x-form.input id="ing_lote" name="lote" placeholder="Opcional" />
                                    </x-form.campo>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm"
                                @click="ingresando = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Registrar ingreso</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endpuede

        @puede('inventario.ajustar')
            <div x-show="ajustando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-ajuste"
                @keydown.escape.window="ajustando = false"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="ajustando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="ajustando"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-ajuste" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Ajustar inventario
                    </h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Conteo físico de <b x-text="sel.nombre"></b>. El sistema dice
                        <span x-text="sel.stock"></span> <span x-text="sel.unidad"></span>.
                    </p>

                    <form method="POST" action="{{ route('inventario.ajuste') }}" class="space-y-5">
                        @csrf
                        @unEnvio
                        <input type="hidden" name="producto_id" :value="sel.id" />

                        <x-form.campo label="Stock realmente contado" for="aju_contado" name="stock_contado" required>
                            <x-form.input id="aju_contado" name="stock_contado" type="number" min="0" required
                                x-model.number="contado" x-bind:step="sel.paso" />
                        </x-form.campo>

                        {{-- La diferencia se muestra antes de guardar: es la cifra
                             que después hay que explicar en el motivo. --}}
                        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Diferencia
                            </p>
                            <p class="text-lg font-semibold"
                                :class="diferencia === 0
                                    ? 'text-gray-500 dark:text-gray-400'
                                    : (diferencia > 0
                                        ? 'text-success-700 dark:text-success-500'
                                        : 'text-error-600 dark:text-error-400')">
                                <span x-text="diferencia > 0 ? '+' + diferencia : diferencia"></span>
                                <span x-text="sel.unidad"></span>
                            </p>
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400"
                                x-text="diferencia === 0
                                    ? 'El conteo coincide: no se registra ningún movimiento.'
                                    : (diferencia > 0 ? 'Sobra mercadería frente a lo que dice el sistema.' : 'Falta mercadería frente a lo que dice el sistema.')">
                            </p>
                        </div>

                        <x-form.campo label="Motivo" for="aju_motivo" name="motivo" required
                            help="Obligatorio: un descuadre sin explicación no sirve de nada.">
                            <x-form.input id="aju_motivo" name="motivo" required
                                placeholder="Conteo mensual, rotura, merma…" />
                        </x-form.campo>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm"
                                @click="ajustando = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Registrar ajuste</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endpuede

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            El stock nunca se escribe a mano: cambia por una venta, una devolución, un ingreso de mercadería o un
            ajuste, y cada cambio queda en el kardex con su responsable y su motivo. Una corrección no borra nada,
            es un movimiento más.
        </p>
    </div>
@endsection
