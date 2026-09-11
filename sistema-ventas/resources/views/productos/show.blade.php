@extends('layouts.app')

@php
    use App\Support\Config;

    $unidad = $producto->unidadMedida;
    $paso = $unidad?->permite_decimal ? '0.001' : '1';
@endphp

@section('content')
    <div x-data="{ ingresando: false, ajustando: false, borrando: false }"
        @keydown.escape.window="ingresando = false; ajustando = false; borrando = false" class="space-y-6">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-4">
                    <x-ui.foto-producto :producto="$producto" size="lg" />
                    <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $producto->nombre }}</h2>
                    <p class="font-mono text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $producto->codigo }}
                        @if ($producto->codigo_barras)
                            · {{ $producto->codigo_barras }}
                        @endif
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.estado estado="INDEFINIDO" :texto="$producto->categoria?->nombre" />
                        <x-ui.estado estado="PRACTICAS" :texto="$unidad?->etiqueta" />
                        @if ($producto->tieneEmpaque())
                            {{-- Solo se baja el nombre del empaque: el código de la
                                 unidad es una sigla y en minúsculas se lee mal. --}}
                            <x-ui.estado estado="PRACTICAS"
                                :texto="'Llega en '.mb_strtolower($producto->nombre_empaque).' de '.Config::cantidad($producto->contenido_empaque).' '.$unidad?->codigo" />
                        @endif
                        <x-ui.estado :estado="$producto->activo ? 'ACTIVO' : 'CESADO'"
                            :texto="$producto->activo ? 'En catálogo' : 'Descatalogado'" />
                        @if ($producto->sin_stock)
                            <x-ui.estado estado="CESADO" texto="Agotado" />
                        @elseif ($producto->bajo_minimo)
                            <x-ui.estado estado="SUSPENDIDO" texto="Bajo el mínimo" />
                        @endif
                    </div>
                    @if ($producto->descripcion)
                        <p class="mt-3 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">
                            {{ $producto->descripcion }}
                        </p>
                    @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @puede('inventario.ingresar')
                        <x-ui.button size="sm" @click="ingresando = true">Ingresar mercadería</x-ui.button>
                    @endpuede
                    @puede('inventario.ajustar')
                        <x-ui.button size="sm" variant="outline" @click="ajustando = true">Ajustar stock</x-ui.button>
                    @endpuede
                    @puede('productos.gestionar')
                        <x-ui.button size="sm" variant="outline" :href="route('productos.edit', $producto)">Editar</x-ui.button>
                    @endpuede
                </div>
            </div>
        </div>

        {{-- Cifras --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $cifras = [
                    // Las dos líneas, no una u otra: cuántas cajas es eso y
                    // cuál era el mínimo. Son las dos cosas que se miran para
                    // decidir si hay que llamar al proveedor.
                    ['Stock actual', Config::cantidad($producto->stock_actual).' '.$unidad?->codigo,
                        $producto->bajo_minimo ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90',
                        collect([$producto->stock_desglosado, 'mínimo '.Config::cantidad($producto->stock_minimo)])
                            ->filter()->implode(' · ')],
                    // Sin impuesto el precio de estante y el de venta son el mismo
                    // numero: no tiene sentido anunciarlo como si fueran dos cosas.
                    [Config::tasaImpuesto() > 0 ? 'Precio de estante' : 'Precio de venta',
                        Config::importe($producto->precio_estante), 'text-gray-800 dark:text-white/90',
                        Config::tasaImpuesto() > 0
                            ? ($producto->afecto_impuesto ? 'incluye impuesto' : 'exonerado')
                            : 'lo que paga el cliente'],
                    ['Ganancia por unidad', Config::importe($producto->margen),
                        $producto->margen >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400',
                        $producto->margen_porcentaje !== null ? $producto->margen_porcentaje.'% de margen' : null],
                    ['Valor en inventario', Config::importe($producto->valor_inventario), 'text-gray-800 dark:text-white/90',
                        'al precio de compra'],
                ];
            @endphp

            @foreach ($cifras as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Kardex --}}
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="px-6 py-5">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Kardex</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Cada movimiento de stock, con su responsable. No se edita ni se borra: una corrección es
                            otro movimiento.
                        </p>
                    </div>

                    <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    @foreach (['Fecha', 'Movimiento', 'Cantidad', 'Stock', 'Responsable'] as $columna)
                                        <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                            {{ $columna }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($movimientos as $movimiento)
                                    <tr>
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $movimiento->fecha?->format('d/m/Y H:i') }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                                {{ $movimiento->etiqueta_origen }}
                                            </span>
                                            @if ($movimiento->motivo)
                                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                    {{ $movimiento->motivo }}
                                                </span>
                                            @endif
                                            @if ($movimiento->proveedor || $movimiento->documento_externo)
                                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                    {{ $movimiento->proveedor?->razon_social }}
                                                    @if ($movimiento->documento_externo)
                                                        · {{ $movimiento->documento_externo }}
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-sm font-medium {{ $movimiento->variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                            {{ $movimiento->variacion > 0 ? '+' : '' }}{{ Config::cantidad($movimiento->variacion) }}
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ Config::cantidad($movimiento->stock_anterior) }}
                                            → <b class="text-gray-800 dark:text-white/90">{{ Config::cantidad($movimiento->stock_resultante) }}</b>
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $movimiento->usuario?->usuario ?? 'sistema' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                            Este producto todavía no tiene movimientos de inventario.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-common.paginacion :paginador="$movimientos" />
                </div>
            </div>

            {{-- Datos --}}
            <div class="space-y-6">
                @php
                    $filasDePrecio = Config::tasaImpuesto() > 0
                        ? [
                            'Precio de compra' => Config::importe($producto->precio_compra).' (sin impuesto)',
                            'Precio de venta base' => Config::importe($producto->precio_venta).' (sin impuesto)',
                            'Precio de estante' => Config::importe($producto->precio_estante),
                        ]
                        : [
                            'Precio de compra' => Config::importe($producto->precio_compra),
                            'Precio de venta' => Config::importe($producto->precio_venta),
                            'Ganancia por unidad' => Config::importe($producto->margen)
                                .($producto->margen_porcentaje !== null ? ' ('.$producto->margen_porcentaje.'% de margen)' : ''),
                        ];

                    // Todos los precios del sistema son por unidad de venta. El
                    // de la caja se muestra calculado, y solo para poder
                    // contrastarlo con la factura del proveedor de un vistazo.
                    if ($producto->tieneEmpaque()) {
                        $filasDePrecio['Costo por '.mb_strtolower($producto->nombre_empaque).' de '.Config::cantidad($producto->contenido_empaque)] =
                            Config::importe((float) $producto->precio_compra * $producto->contenido_empaque);
                    }
                @endphp

                <x-common.component-card title="Precios">
                    <dl class="space-y-4">
                        @foreach ($filasDePrecio as $etiqueta => $valor)
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $etiqueta }}
                                </dt>
                                <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $valor }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-common.component-card>

                <x-common.component-card title="Proveedor">
                    @if ($producto->proveedor)
                        <dl class="space-y-3">
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Razón social
                                </dt>
                                <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $producto->proveedor->razon_social }}
                                </dd>
                            </div>
                            @if ($producto->proveedor->telefono || $producto->proveedor->email)
                                <div>
                                    <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Contacto
                                    </dt>
                                    <dd class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ collect([$producto->proveedor->telefono, $producto->proveedor->email])->filter()->implode(' · ') }}
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Este producto no tiene proveedor habitual asignado.
                        </p>
                    @endif
                </x-common.component-card>

                @puede('productos.gestionar')
                    <x-common.component-card title="Retirar del catálogo">
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Si el producto tiene movimientos, se descataloga en lugar de eliminarse.
                        </p>
                        <x-ui.button variant="outline" size="sm" class="w-full" @click="borrando = true">
                            Eliminar producto
                        </x-ui.button>
                    </x-common.component-card>
                @endpuede
            </div>
        </div>

        {{-- Ingreso de mercadería --}}
        @puede('inventario.ingresar')
            <div x-show="ingresando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-ingreso"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="ingresando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="ingresando"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-ingreso" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Ingresar mercadería</h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Entrada de <b>{{ $producto->nombre }}</b>. Stock actual:
                        {{ Config::cantidad($producto->stock_actual) }} {{ $unidad?->codigo }}@if ($producto->stock_desglosado)
                            ({{ $producto->stock_desglosado }})
                        @endif.
                    </p>

                    {{-- El estado del costo vive en el formulario y no en el modal
                         entero: solo sirve para decidir si ofrecer actualizar el
                         costo del producto. --}}
                    <form method="POST" action="{{ route('productos.ingreso', $producto) }}" class="space-y-5"
                        x-data="{
                            costo: @js((float) $producto->precio_compra),
                            costoPor: 'UNIDAD',
                            actualizarCosto: true,
                            contenido: {{ (float) $producto->contenido_empaque }},
                            actual: {{ (float) $producto->precio_compra }},
                            get costoEscrito() {
                                return this.costo !== '' && this.costo !== null && Number(this.costo) >= 0;
                            },
                            get costoNuevo() {
                                const c = Number(this.costo) || 0;
                                return this.costoPor === 'EMPAQUE' && this.contenido > 0
                                    ? Math.round((c / this.contenido) * 100) / 100
                                    : c;
                            },
                        }">
                        @csrf

                        <x-form.cantidad-empaque :help="$unidad?->permite_decimal ? 'Admite decimales.' : 'Solo números enteros.'"
                            :hay-empaque="$producto->tieneEmpaque() ? 'true' : 'false'"
                            :contenido="(float) $producto->contenido_empaque"
                            :empaque="json_encode(mb_strtolower($producto->nombre_empaque ?? ''))"
                            :unidad="json_encode($unidad?->codigo ?? '')" :paso="$paso" />

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <x-form.campo :label="$producto->tieneEmpaque() ? 'Costo' : 'Costo unitario'"
                                for="costo_unitario" name="costo_unitario"
                                :class="$producto->tieneEmpaque() ? 'sm:col-span-2' : ''"
                                help="Opcional. Lo que costó esta compra, sin impuesto.">
                                {{-- El ancho va en un envoltorio y no en el propio
                                     control: los componentes de formulario traen
                                     `w-full`, y una clase de ancho puesta encima
                                     pierde por orden de la hoja de estilos. --}}
                                <div class="flex gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-form.input id="costo_unitario" name="costo_unitario" type="number"
                                            step="0.01" min="0" :value="$producto->precio_compra"
                                            x-model="costo" />
                                    </div>

                                    {{-- La factura del proveedor viene por caja, no por
                                         unidad. Se acepta como está y la división la hace
                                         el sistema. --}}
                                    @if ($producto->tieneEmpaque())
                                        <div class="w-36 shrink-0">
                                            <x-form.select name="costo_por" x-model="costoPor" :opciones="[
                                                'UNIDAD' => 'por '.mb_strtolower($unidad?->nombre ?? 'unidad'),
                                                'EMPAQUE' => 'por '.mb_strtolower($producto->nombre_empaque),
                                            ]" />
                                        </div>
                                    @endif
                                </div>

                                {{-- Sin esto, el costo se quedaba solo en el kardex y el
                                     producto seguía con el precio del alta: el margen y
                                     el valor del inventario mentían en silencio desde la
                                     primera subida del proveedor. --}}
                                <div x-show="costoEscrito && costoNuevo !== actual" x-cloak class="mt-3">
                                    <x-form.check name="actualizar_costo" :checked="true" model="actualizarCosto">
                                        Actualizar el costo de este producto
                                        (<span x-text="actual.toFixed(2)"></span> →
                                        <b x-text="costoNuevo.toFixed(2)"></b>)
                                    </x-form.check>
                                </div>
                            </x-form.campo>

                            <x-form.campo label="Proveedor" for="ingreso_proveedor" name="proveedor_id">
                                <x-form.select id="ingreso_proveedor" name="proveedor_id"
                                    :value="$producto->proveedor_id" placeholder="Sin especificar"
                                    :opciones="$proveedores" />
                            </x-form.campo>

                            <x-form.campo label="Guía o factura" for="documento_externo" name="documento_externo"
                                help="El documento con el que llegó la mercadería.">
                                <x-form.input id="documento_externo" name="documento_externo"
                                    placeholder="F001-00123" />
                            </x-form.campo>

                            <x-form.campo label="Observación" for="ingreso_motivo" name="motivo">
                                <x-form.input id="ingreso_motivo" name="motivo" placeholder="Opcional" />
                            </x-form.campo>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="ingresando = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Registrar ingreso</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endpuede

        {{-- Ajuste por conteo --}}
        @puede('inventario.ajustar')
            <div x-show="ajustando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-ajuste"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="ajustando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-data="{ contado: {{ (float) $producto->stock_actual }}, sistema: {{ (float) $producto->stock_actual }} }"
                    x-trap.inert.noscroll="ajustando"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-ajuste" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Ajustar inventario</h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Indica cuántas unidades hay <b>realmente</b> en el estante y el sistema calcula la diferencia.
                    </p>

                    <form method="POST" action="{{ route('productos.ajuste', $producto) }}" class="space-y-5">
                        @csrf

                        <x-form.campo label="Stock contado" for="stock_contado" name="stock_contado" required>
                            <x-form.input id="stock_contado" name="stock_contado" type="number" step="{{ $paso }}"
                                min="0" :value="Config::cantidad($producto->stock_actual)" x-model.number="contado"
                                required />
                        </x-form.campo>

                        <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Diferencia frente al sistema ({{ Config::cantidad($producto->stock_actual) }})
                            </p>
                            <p class="text-lg font-semibold"
                                :class="(contado - sistema) === 0
                                    ? 'text-gray-500 dark:text-gray-400'
                                    : ((contado - sistema) > 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400')">
                                <span x-text="(contado - sistema) > 0 ? '+' : ''"></span><span
                                    x-text="Math.round((contado - sistema) * 1000) / 1000"></span>
                                {{ $unidad?->codigo }}
                            </p>
                            <p x-show="(contado - sistema) < 0" class="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                                Faltan unidades: puede ser merma, rotura o un faltante sin explicar.
                            </p>
                        </div>

                        <x-form.campo label="Motivo" for="ajuste_motivo" name="motivo" required
                            help="Obligatorio: un ajuste sin explicación es un descuadre sin responsable.">
                            <x-form.textarea id="ajuste_motivo" name="motivo"
                                placeholder="Conteo físico mensual, merma por rotura, producto vencido…" required />
                        </x-form.campo>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="ajustando = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Registrar ajuste</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endpuede

        {{-- Baja --}}
        @puede('productos.gestionar')
            <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-producto"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="borrando"
                    class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-eliminar-producto" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar producto</h2>
                    <p class="mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        ¿Eliminar <b>{{ $producto->nombre }}</b>? Si tiene movimientos de inventario o ventas, se
                        descatalogará en lugar de eliminarse, para no romper el histórico.
                    </p>

                    <form method="POST" action="{{ route('productos.destroy', $producto) }}" class="flex justify-end gap-3">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                    </form>
                </div>
            </div>
        @endpuede
    </div>
@endsection
