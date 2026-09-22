@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    {{-- Si la validación del ajuste rebota, el modal se reabre con lo escrito. --}}
    <div x-data="{
        ajustando: @js($errors->has('contado') || $errors->has('motivo')),
        id: @js(old('producto_id')),
        nombre: @js(old('producto_nombre', '')),
        actual: @js(old('producto_actual', '')),
        contado: @js(old('contado', '')),
        ajustar(producto) {
            this.id = producto.id;
            this.nombre = producto.nombre;
            this.actual = producto.actual;
            this.contado = '';
            this.ajustando = true;
        }
    }" @keydown.escape.window="ajustando = false" class="space-y-6">

        @if (! $hayControlados)
            {{-- Nada lleva inventario todavía: se explica qué es y dónde se activa. --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-8">
                <h2 class="mb-3 text-lg font-semibold text-gray-800 dark:text-white/90">Todavía nada lleva inventario</h2>
                <div class="max-w-2xl space-y-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    <p>
                        El stock es solo para lo que se <b class="text-gray-700 dark:text-gray-300">compra hecho y se revende</b>:
                        las bebidas embotelladas, el agua, las latas. Los platos se preparan en la cocina y no llevan stock.
                    </p>
                    <p>
                        Para empezar, abre la ficha de la bebida en el <b class="text-gray-700 dark:text-gray-300">Menú</b> y
                        marca <b class="text-gray-700 dark:text-gray-300">«Lleva inventario»</b>. Ahí mismo se pone el empaque
                        en que la trae el proveedor (caja de 12, paquete de 6), el costo y el stock mínimo.
                    </p>
                </div>
                <div class="mt-5">
                    <x-ui.button size="sm" :href="route('productos.index')">Ir al Menú</x-ui.button>
                </div>
            </div>
        @else
            @php
                $tarjetas = [
                    ['Llevan stock', number_format($resumen['productos']), 'text-gray-800 dark:text-white/90', 'productos activos'],
                    ['Bajo el mínimo', number_format($resumen['bajo_minimo']), 'text-warning-700 dark:text-orange-400', 'hay que comprar'],
                    ['En negativo', number_format($resumen['negativos']), 'text-error-600 dark:text-error-400', 'se vendió sin stock: revisar'],
                    ['Valor del inventario', Config::importe($resumen['valor']), 'text-success-700 dark:text-success-500', 'stock al último costo'],
                ];
            @endphp

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                        <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    </div>
                @endforeach
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <form method="GET" action="{{ route('inventario.index') }}"
                    class="flex flex-col gap-4 lg:flex-row lg:items-end">
                    <div class="flex-1">
                        <x-form.campo label="Buscar" for="buscar">
                            <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="Nombre o código" />
                        </x-form.campo>
                    </div>
                    <div class="flex flex-wrap items-center gap-4 lg:pb-3">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="checkbox" name="alerta" value="1" @checked($filtros['alerta'])
                                class="h-4 w-4 rounded border-gray-300 text-brand-500 dark:border-gray-700" />
                            Solo bajo el mínimo o en negativo
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="checkbox" name="inactivos" value="1" @checked($filtros['inactivos'])
                                class="h-4 w-4 rounded border-gray-300 text-brand-500 dark:border-gray-700" />
                            Incluir los que ya no están en el menú
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                        <x-ui.button variant="outline" size="sm" :href="route('inventario.index')">Limpiar</x-ui.button>
                        <x-ui.button size="sm" :href="route('compras.sugerida')">Compra sugerida</x-ui.button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="max-w-full overflow-x-auto overscroll-x-contain">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <x-tabla.th>Producto</x-tabla.th>
                                <x-tabla.th class="hidden md:table-cell">Categoría</x-tabla.th>
                                <x-tabla.th class="hidden lg:table-cell">Empaque</x-tabla.th>
                                <x-tabla.th derecha>Stock</x-tabla.th>
                                <x-tabla.th derecha class="hidden sm:table-cell">Mínimo</x-tabla.th>
                                <x-tabla.th derecha class="hidden lg:table-cell">Costo</x-tabla.th>
                                <x-tabla.th derecha class="hidden md:table-cell">Valor</x-tabla.th>
                                <x-tabla.th>Estado</x-tabla.th>
                                <x-tabla.th derecha>Acciones</x-tabla.th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($productos as $producto)
                                @php
                                    $stock = (float) $producto->stock_actual;
                                    $datos = [
                                        'id' => $producto->id,
                                        'nombre' => $producto->nombre,
                                        'actual' => Config::cantidad($stock).' u.'
                                            .($producto->empaque_visible && $stock > 0 ? ' ('.$producto->stock_en_empaques.')' : ''),
                                    ];
                                @endphp
                                <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <td class="px-5 py-4">
                                        <a href="{{ route('inventario.kardex', $producto) }}"
                                            class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                            {{ $producto->nombre }}
                                        </a>
                                        <span class="block font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $producto->codigo }}
                                            @unless ($producto->activo)
                                                · fuera del menú
                                            @endunless
                                        </span>
                                    </td>
                                    <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                        {{ $producto->categoria?->nombre ?? '—' }}
                                    </td>
                                    <td class="hidden px-5 py-4 text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                        {{ $producto->empaque_visible ?? 'Por unidad' }}
                                    </td>
                                    <td class="px-5 py-4 text-right whitespace-nowrap">
                                        <span class="font-medium text-theme-sm tabular-nums {{ $stock < 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                                            {{ Config::cantidad($stock) }} u.
                                        </span>
                                        @if ($producto->empaque_visible && $stock > 0)
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400">{{ $producto->stock_en_empaques }}</span>
                                        @endif
                                    </td>
                                    <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 sm:table-cell dark:text-gray-400">
                                        {{ Config::cantidad($producto->stock_minimo) }}
                                    </td>
                                    <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 lg:table-cell dark:text-gray-400">
                                        {{ $producto->costo !== null ? Config::importe($producto->costo) : '—' }}
                                    </td>
                                    <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-800 md:table-cell dark:text-white/90">
                                        {{ Config::importe(max($stock, 0) * (float) $producto->costo) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($stock < 0)
                                            <span class="inline-flex items-center rounded-full bg-error-50 px-2.5 py-0.5 text-theme-xs font-medium text-error-700 dark:bg-error-500/15 dark:text-error-400"
                                                title="El stock quedó negativo: falta registrar una compra o contar de nuevo.">
                                                Revisar: se vendió sin stock
                                            </span>
                                        @elseif ($producto->bajo_minimo)
                                            <span class="inline-flex items-center rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-orange-400">
                                                Comprar
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-success-50 px-2.5 py-0.5 text-theme-xs font-medium text-success-700 dark:bg-success-500/15 dark:text-success-500">
                                                OK
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <x-ui.button size="xs" variant="outline" @click="ajustar(@js($datos))">Ajustar</x-ui.button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                        No hay productos con esos criterios.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <x-common.paginacion :paginador="$productos" />
            </div>

            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                La venta nunca se frena por falta de stock: si se vende más de lo registrado, el stock queda en negativo
                y aparece en rojo hasta que se registra la compra o se cuenta de nuevo. Para contar todas las bebidas de
                una vez, usa la <a href="{{ route('tomas.index') }}" class="text-brand-500 hover:text-brand-600">toma de inventario</a>.
            </p>
        @endif

        {{-- Ajuste de un producto --}}
        <div x-show="ajustando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-ajuste"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="ajustando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="ajustando"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-ajuste" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                    Ajustar stock
                </h2>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    <b class="text-gray-700 dark:text-gray-300" x-text="nombre"></b>: el sistema dice
                    <b class="text-gray-700 dark:text-gray-300" x-text="actual"></b>. Escribe lo que hay de verdad, en
                    unidades sueltas; la diferencia queda en el kardex con el motivo.
                </p>

                <form method="POST" :action="`{{ url('inventario') }}/${id}/ajuste`" class="space-y-5">
                    @csrf
                    @unEnvio
                    {{-- Solo para reabrir el modal si la validación rebota. --}}
                    <input type="hidden" name="producto_id" :value="id" />
                    <input type="hidden" name="producto_nombre" :value="nombre" />
                    <input type="hidden" name="producto_actual" :value="actual" />

                    <x-form.campo label="Stock contado (unidades)" for="ajuste-contado" name="contado" required>
                        <x-form.input id="ajuste-contado" name="contado" type="number" step="1" min="0" x-model="contado"
                            required />
                    </x-form.campo>

                    <x-form.campo label="Motivo" for="ajuste-motivo" name="motivo" required
                        help="Una rotura, una botella que se regaló, un conteo rápido…">
                        <x-form.input id="ajuste-motivo" name="motivo" maxlength="255" placeholder="Se rompieron 2 botellas"
                            required />
                    </x-form.campo>

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="ajustando = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar ajuste</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
