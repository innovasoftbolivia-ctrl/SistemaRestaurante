@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">
        {{-- «Sin fecha» va aquí arriba y no escondido: si quedaron 200 unidades
             sin fechar, «no tengo nada por vencer» no quiere decir que todo esté
             bien, quiere decir que el control todavía no está completo. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $tarjetas = [
                    ['Ya vencidos', number_format($resumen['vencidos']),
                        $resumen['vencidos'] > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90',
                        $resumen['vencidos'] > 0 ? Config::importe($resumen['valor_vencido']).' inmovilizados' : 'nada vencido'],
                    ['Por vencer', number_format($resumen['por_vencer']),
                        $resumen['por_vencer'] > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-800 dark:text-white/90',
                        'en los próximos '.$dias.' días'],
                    ['Sin fecha registrada', Config::cantidad($resumen['sin_fecha']),
                        'text-gray-800 dark:text-white/90', 'unidades que nadie fechó'],
                    ['Productos con control', number_format($resumen['controlados']),
                        'text-gray-800 dark:text-white/90', 'el resto no vence'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        {{-- La ventana de aviso, a un clic --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">Mirar lo que vence dentro de</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($ventanas as $ventana)
                    <a href="{{ route('vencimientos.index', ['dias' => $ventana]) }}"
                        class="rounded-lg px-3 py-2 text-theme-xs font-medium transition {{ $dias === $ventana
                            ? 'bg-brand-500 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10' }}">
                        {{ $ventana }} días
                    </a>
                @endforeach
            </div>
            <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                Lo ya vencido sale siempre, sin importar la ventana elegida.
            </p>
        </div>

        {{-- El listado --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Producto', 'Vence', 'Faltan', 'Quedan', 'Valor', ''] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $i >= 2 ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($lotes as $lote)
                            @php($vencido = $lote->dias < 0)
                            <tr class="{{ $vencido ? 'bg-error-50/50 dark:bg-error-500/5' : '' }}">
                                <td class="px-5 py-4">
                                    <a href="{{ route('vencimientos.producto', $lote->producto_id) }}"
                                        class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $lote->producto }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $lote->producto_codigo }}@if ($lote->codigo) · lote {{ $lote->codigo }}@endif
                                    </span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ \Illuminate\Support\Carbon::parse($lote->fecha_vencimiento)->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium {{ $vencido ? 'text-error-600 dark:text-error-400' : ($lote->dias <= 7 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400') }}">
                                    @if ($vencido)
                                        vencido hace {{ abs($lote->dias) }} d
                                    @elseif ($lote->dias === 0)
                                        vence hoy
                                    @else
                                        {{ $lote->dias }} d
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($lote->cantidad_actual) }} {{ $lote->unidad }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($lote->valor) }}
                                </td>
                                {{-- Ver el problema y poder resolverlo desde el
                                     mismo sitio. Cuál de las dos salidas se
                                     ofrece no es cosmético: al proveedor solo se
                                     le puede devolver lo que él trajo, y solo si
                                     esa línea todavía tiene algo sin devolver.
                                     Lo demás —el stock que ya estaba cuando se
                                     encendió el control— sale por un ajuste,
                                     que es lo honesto: no hay factura contra la
                                     que reclamar. --}}
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    @if ($lote->compra_id && $lote->pendiente_devolucion > 0)
                                        @puede('inventario.ingresar')
                                            <x-ui.button size="sm" variant="outline"
                                                :href="route('devoluciones-compra.create', ['compra' => $lote->compra_id, 'lote' => $lote->id])">
                                                Devolver
                                            </x-ui.button>
                                        @endpuede
                                    @else
                                        @puede('inventario.ajustar')
                                            <x-ui.button size="sm" variant="outline"
                                                :href="route('inventario.index', ['buscar' => $lote->producto_codigo])">
                                                Ajustar
                                            </x-ui.button>
                                        @endpuede
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Nada vence en los próximos {{ $dias }} días.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$lotes" />
        </div>

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            El mostrador despacha siempre del lote que vence antes, así que lo que aparece aquí es lo que de verdad
            queda de esa tanda. <strong>Devolver</strong> lleva a la factura por la que entró esa tanda, con la
            cantidad y el motivo ya puestos. Sale solo cuando hay una compra detrás a la que todavía le queda algo
            por devolver; lo demás se saca con un <strong>ajuste</strong>, que deja la merma explicada y con
            responsable, y no se confunde con una venta.
        </p>
    </div>
@endsection
