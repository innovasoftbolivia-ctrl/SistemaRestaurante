@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">

        @php
            // Sin impuesto configurado (negocio que no factura todavía), la
            // tarjeta no aporta nada: sería un «Bs 0.00» fijo en pantalla.
            $tarjetas = [
                ['Operaciones', number_format($resumen['operaciones']), 'text-gray-800 dark:text-white/90', 'sin contar anuladas'],
                ['Vendido', Config::importe($resumen['vendido']), 'text-success-700 dark:text-success-500', Config::facturacionVisible() ? 'con impuesto' : null],
            ];

            if (App\Support\Config::tasaImpuesto() > 0 && App\Support\Config::facturacionVisible()) {
                $tarjetas[] = ['Impuesto', Config::importe($resumen['impuesto']), 'text-gray-800 dark:text-white/90', 'incluido en el total'];
            }

            $tarjetas[] = ['Anuladas', number_format($resumen['anuladas']), 'text-error-600 dark:text-error-400', 'no suman en lo vendido'];
        @endphp

        <div class="grid grid-cols-2 gap-4 {{ count($tarjetas) === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('ventas.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-start">
                <x-form.campo label="Buscar" for="buscar" class="sm:col-span-2"
                    help="Comprobante, cliente o número de venta.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="F001-000012" />
                </x-form.campo>

                <x-form.campo label="Estado" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Todos"
                        :opciones="array_combine($estados, array_map(fn ($e) => ucfirst(str_replace('_', ' ', mb_strtolower($e))), $estados))" />
                </x-form.campo>

                @unless ($soloPropias)
                    <x-form.campo label="Cajero" for="usuario">
                        <x-form.select id="usuario" name="usuario" :value="$filtros['usuario']" placeholder="Todos"
                            :opciones="$cajeros" />
                    </x-form.campo>
                @endunless

                <x-form.campo label="Desde" for="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-6">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('ventas.index')">Limpiar</x-ui.button>
                    @puede('ventas.registrar')
                        <x-ui.button size="sm" class="ml-auto" :href="route('pos.index')">Nueva venta</x-ui.button>
                    @endpuede
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            {{-- La visibilidad va por columna: en el teléfono se
                                 muestran comprobante, estado y total; lo demás
                                 aparece a medida que cabe.

                                 «Comprobante» no se ordena: una venta puede tener
                                 más de uno cuando se sustituye recibo por factura,
                                 y no hay un único número por el que ordenarla. --}}
                            <x-tabla.th>Comprobante</x-tabla.th>
                            <x-tabla.th clave="fecha" defecto inicial="desc" class="hidden sm:table-cell">Fecha</x-tabla.th>
                            {{-- «Pedido» tampoco se ordena: el número vuelve a 1 en
                                 cada jornada, y las ventas de antes de los pedidos no
                                 tienen ninguno: ordenar por ahí no ayuda a encontrar
                                 nada. --}}
                            <x-tabla.th class="hidden md:table-cell">Pedido</x-tabla.th>
                            <x-tabla.th clave="cliente" class="hidden md:table-cell">Cliente</x-tabla.th>
                            <x-tabla.th clave="cajero" class="hidden lg:table-cell">Cajero</x-tabla.th>
                            <x-tabla.th clave="estado">Estado</x-tabla.th>
                            <x-tabla.th clave="total" inicial="desc" derecha>Total</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($ventas as $venta)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <a href="{{ route('ventas.show', $venta) }}"
                                        class="block font-mono font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $venta->comprobante?->numero_completo ?? 'sin comprobante' }}
                                    </a>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">venta #{{ $venta->id }}</span>
                                </td>
                                <td class="hidden px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ $venta->fecha?->format('d/m/Y H:i') }}
                                </td>
                                <td class="hidden px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    @if ($venta->pedido)
                                        {{ $venta->pedido->destino }}
                                        {{-- Con la jornada: el listado mezcla días y el número
                                             vuelve a 1 en cada jornada, así que «pedido 7» a secas
                                             sería el de hoy y el de la semana pasada a la vez. Es la
                                             jornada y no la fecha de apertura: el pedido de la 01:30
                                             se numeró con los de la noche anterior. --}}
                                        <span class="block text-theme-xs text-gray-400 dark:text-gray-500">
                                            {{ $venta->pedido->numero_visible }} · {{ $venta->pedido->jornada?->format('d/m/Y') }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $venta->cliente?->nombre ?? Config::get('cliente_generico_nombre', 'Cliente varios') }}
                                </td>
                                <td class="hidden px-5 py-4 text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    {{ $venta->usuario?->usuario }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$venta->estado === 'ANULADA' ? 'CESADO' : ($venta->estado === 'COMPLETADA' ? 'ACTIVO' : 'SUSPENDIDO')"
                                        :texto="ucfirst(str_replace('_', ' ', mb_strtolower($venta->estado)))" />
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <span class="font-medium text-theme-sm {{ $venta->estado === 'ANULADA' ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-white/90' }}">
                                        {{ Config::importe($venta->total) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay ventas con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$ventas" />
        </div>
    </div>
@endsection
