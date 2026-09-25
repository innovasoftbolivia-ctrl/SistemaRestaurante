@extends('layouts.app')

@php
    use App\Support\Config;

    $abierta = $toma->estaAbierta();
    $estados = [
        'ABIERTA' => ['SUSPENDIDO', 'En curso'],
        'CERRADA' => ['ACTIVO', 'Cerrada'],
        'CANCELADA' => ['SIN_CUENTA', 'Cancelada'],
    ];
@endphp

@section('content')
    <div class="space-y-6" x-data="{ cerrando: false, cancelando: false }"
        @keydown.escape.window="cerrando = false; cancelando = false">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Toma #{{ $toma->id }}</h2>
                        <x-ui.estado :estado="$estados[$toma->estado][0]" :texto="$estados[$toma->estado][1]" />
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Abierta el {{ $toma->fecha_apertura?->format('d/m/Y H:i') }} por {{ $toma->usuarioApertura?->usuario }}
                        @if ($toma->fecha_cierre)
                            · {{ $toma->estado === 'CERRADA' ? 'cerrada' : 'cancelada' }} el
                            {{ $toma->fecha_cierre->format('d/m/Y H:i') }} por {{ $toma->usuarioCierre?->usuario }}
                        @endif
                    </p>
                    @if ($toma->observacion)
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $toma->observacion }}</p>
                    @endif
                </div>

                @if ($abierta)
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="outline" @click="cancelando = true">Cancelar toma</x-ui.button>
                        <x-ui.button size="sm" variant="success" @click="cerrando = true">Cerrar y ajustar</x-ui.button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Resumen --}}
        @php
            $tarjetas = [
                ['Contados', number_format($resumen['contados']).' / '.number_format($resumen['total']), 'text-gray-800 dark:text-white/90', $abierta ? 'lo que falta queda como está' : 'productos de la planilla'],
                ['Con diferencia', number_format($resumen['con_diferencia']), 'text-gray-800 dark:text-white/90', 'no cuadraban con el sistema'],
                ['Faltante', Config::importe($resumen['faltante']), 'text-error-600 dark:text-error-400', 'al costo del conteo'],
                ['Sobrante', Config::importe($resumen['sobrante']), 'text-success-700 dark:text-success-500', 'al costo del conteo'],
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

        @if ($abierta)
            <x-ui.alert variant="info"
                message="Escribe lo contado en unidades sueltas (una caja de 12 son 12). Puedes guardar por partes: lo que dejes vacío no se toca. Si corriges un número, se vuelve a tomar lo que dice el sistema en ese momento." />
        @endif

        {{-- La planilla --}}
        <form method="POST" action="{{ route('tomas.contar', $toma) }}"
            class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            @csrf
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th class="hidden md:table-cell">Empaque</x-tabla.th>
                            <x-tabla.th derecha>Contado</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Sistema al contar</x-tabla.th>
                            <x-tabla.th derecha>Diferencia</x-tabla.th>
                            @unless ($abierta)
                                <x-tabla.th derecha class="hidden md:table-cell">Valor</x-tabla.th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($lineas as $linea)
                            @php
                                $contada = $linea->contado !== null;
                                $diferencia = $contada ? (float) $linea->diferencia : null;
                                $clase = $diferencia === null || round($diferencia, 3) == 0
                                    ? 'text-gray-500 dark:text-gray-400'
                                    : ($diferencia < 0 ? 'text-error-600 dark:text-error-400' : 'text-success-700 dark:text-success-500');
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-gray-800 text-theme-sm dark:text-white/90">{{ $linea->producto?->nombre }}</span>
                                    <span class="block font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $linea->producto?->codigo }}</span>
                                </td>
                                <td class="hidden px-5 py-3 text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $linea->producto?->empaque_visible ?? 'Por unidad' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($abierta)
                                        <label for="contado-{{ $linea->producto_id }}" class="sr-only">Contado de {{ $linea->producto?->nombre }}</label>
                                        <input id="contado-{{ $linea->producto_id }}" type="number" inputmode="numeric" step="1" min="0"
                                            name="contados[{{ $linea->producto_id }}]"
                                            {{-- La hora en que se escribió: el stock del sistema se
                                                 toma de esa hora (ver TomasInventario::contar). --}}
                                            x-on:input="$el.nextElementSibling.value = Date.now()"
                                            value="{{ old('contados.'.$linea->producto_id) }}"
                                            placeholder="{{ $contada ? Config::cantidad($linea->contado) : '—' }}"
                                            class="shadow-theme-xs h-10 w-24 rounded-lg border border-gray-300 bg-transparent px-3 text-right text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/40" />
                                        <input type="hidden" name="contado_en[{{ $linea->producto_id }}]" value="{{ old('contado_en.'.$linea->producto_id) }}" />
                                        @if ($contada)
                                            <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                                                contado: <b>{{ Config::cantidad($linea->contado) }}</b>
                                                · {{ $linea->usuario?->usuario }} {{ $linea->fecha_conteo?->format('H:i') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">
                                            {{ $contada ? Config::cantidad($linea->contado) : 'sin contar' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="hidden px-5 py-3 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ $contada ? Config::cantidad($linea->stock_sistema) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap text-theme-sm font-medium tabular-nums {{ $clase }}">
                                    @if ($diferencia === null)
                                        —
                                    @else
                                        {{ $diferencia > 0 ? '+' : ($diferencia < 0 ? '−' : '') }}{{ Config::cantidad(abs($diferencia)) }}
                                    @endif
                                </td>
                                @unless ($abierta)
                                    <td class="hidden px-5 py-3 text-right whitespace-nowrap text-theme-sm tabular-nums md:table-cell {{ $clase }}">
                                        @if ($diferencia === null || round($diferencia, 3) == 0)
                                            —
                                        @else
                                            {{ $diferencia < 0 ? '−' : '+' }}{{ Config::importe(abs($diferencia) * (float) $linea->costo_unitario) }}
                                        @endif
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-0">
                                    <x-common.vacio icono="lista" titulo="La planilla está vacía." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @unless ($abierta)
                        <tfoot class="border-t border-gray-100 dark:border-gray-800">
                            <tr>
                                <td colspan="6" class="px-5 py-4 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    Faltante <b class="text-error-600 dark:text-error-400">{{ Config::importe($resumen['faltante']) }}</b>
                                    · Sobrante <b class="text-success-700 dark:text-success-500">{{ Config::importe($resumen['sobrante']) }}</b>
                                    · Neto <b class="text-gray-800 dark:text-white/90">{{ Config::importe($resumen['sobrante'] - $resumen['faltante']) }}</b>
                                </td>
                            </tr>
                        </tfoot>
                    @endunless
                </table>
            </div>

            @if ($abierta && $lineas->isNotEmpty())
                <div class="flex justify-end border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                    <x-ui.button type="submit" size="sm">Guardar conteo</x-ui.button>
                </div>
            @endif
        </form>

        @unless ($abierta)
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($toma->estado === 'CERRADA')
                    Las diferencias se aplicaron al stock como movimientos «Toma de inventario» del kardex. El valor usa el
                    costo que tenía cada bebida al contarla.
                @else
                    Esta toma se canceló: el stock no se tocó.
                @endif
            </p>
        @endunless

        @if ($abierta)
            {{-- Cerrar --}}
            <div x-show="cerrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cerrar-toma"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="cerrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="cerrando"
                    class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-cerrar-toma" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Cerrar y ajustar</h2>
                    <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                        Se aplican al stock las diferencias de los {{ $resumen['contados'] }} producto(s) contados: el
                        faltante sale y el sobrante entra, cada uno con su línea en el kardex.
                    </p>
                    <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                        Guarda antes lo que tengas escrito en la planilla: lo que no esté guardado no se aplica.
                    </p>
                    @if ($resumen['total'] > $resumen['contados'])
                        <p class="mb-2 text-theme-sm text-warning-700 dark:text-orange-400">
                            {{ $resumen['total'] - $resumen['contados'] }} producto(s) sin contar quedan como están.
                        </p>
                    @endif
                    <p class="mb-6 text-theme-sm text-gray-500 dark:text-gray-400">Una toma cerrada ya no se puede cambiar.</p>

                    <form method="POST" action="{{ route('tomas.cerrar', $toma) }}" class="flex justify-end gap-3">
                        @csrf
                        @unEnvio
                        <x-ui.button type="button" variant="outline" size="sm" @click="cerrando = false">Volver</x-ui.button>
                        <x-ui.button type="submit" variant="success" size="sm">Cerrar y ajustar</x-ui.button>
                    </form>
                </div>
            </div>

            {{-- Cancelar --}}
            <div x-show="cancelando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cancelar-toma"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="cancelando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="cancelando"
                    class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-cancelar-toma" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Cancelar la toma</h2>
                    <p class="mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        Se descarta lo contado y el stock no se toca. La toma queda en el historial como cancelada.
                    </p>

                    <form method="POST" action="{{ route('tomas.cancelar', $toma) }}" class="flex justify-end gap-3">
                        @csrf
                        <x-ui.button type="button" variant="outline" size="sm" @click="cancelando = false">Volver</x-ui.button>
                        <x-ui.button type="submit" variant="danger" size="sm">Cancelar toma</x-ui.button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
