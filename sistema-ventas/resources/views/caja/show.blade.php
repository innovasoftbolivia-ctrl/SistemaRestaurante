@extends('layouts.app')

@php
    use App\Support\Config;

    $abierta = $sesion->estaAbierta();
    $esPropia = $sesion->usuario_apertura_id === auth()->id();
@endphp

@section('content')
    {{-- `declarado: null`, no el esperado: si arrancara ya igualado, cerrar sin
         cambiar nada reportaría «cuadrado» sin que nadie haya contado un
         céntimo. El monto se cuenta y se escribe; el esperado ya lo calculó
         el sistema, se muestra aparte como referencia, no como valor inicial. --}}
    <div x-data="{ moviendo: false, tipo: 'INGRESO', cerrando: false, declarado: null, esperado: {{ $veArqueo ? $resumen['esperado'] : 'null' }} }"
        @keydown.escape.window="moviendo = false; cerrando = false" class="space-y-6">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="mb-2 flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $sesion->caja?->nombre }}</h2>
                        <x-ui.estado :estado="$abierta ? 'ACTIVO' : 'SIN_CUENTA'"
                            :texto="$abierta ? 'Turno abierto' : 'Turno cerrado'" />
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Abierto por <b class="text-gray-800 dark:text-white/90">{{ $sesion->usuarioApertura?->usuario }}</b>
                        el {{ $sesion->fecha_apertura?->format('d/m/Y H:i') }}
                        @unless ($abierta)
                            · cerrado por {{ $sesion->usuarioCierre?->usuario }}
                            el {{ $sesion->fecha_cierre?->format('d/m/Y H:i') }}
                        @endunless
                    </p>
                    @if ($sesion->observacion)
                        <p class="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Al abrir:</span> {{ $sesion->observacion }}
                        </p>
                    @endif
                    @if ($sesion->observacion_cierre)
                        <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Al cerrar:</span> {{ $sesion->observacion_cierre }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    {{-- Recién al cerrar, no antes: imprimirlo con el turno abierto
                         dejaría el arqueo en blanco, que es justo lo que no se
                         quiere (O4). Cerrar caja ya lleva directo a este mismo
                         documento, ya firmable. --}}
                    @unless ($abierta)
                        <x-ui.button size="sm" variant="outline" :href="route('caja.imprimir', $sesion)" target="_blank">
                            Imprimir resumen
                        </x-ui.button>
                    @endunless

                    @if ($abierta)
                        @puede('ventas.registrar')
                            @if ($esPropia)
                                <x-ui.button size="sm" :href="route('pos.index')">Ir al mostrador</x-ui.button>
                            @endif
                        @endpuede
                        @puede('caja.abrir')
                            @if ($esPropia)
                                <x-ui.button size="sm" variant="outline" @click="moviendo = true">Registrar movimiento</x-ui.button>
                            @endif
                        @endpuede
                        @puede('caja.cerrar')
                            <x-ui.button size="sm" variant="danger" @click="cerrando = true">Cerrar caja</x-ui.button>
                        @endpuede
                    @endif
                </div>
            </div>
        </div>

        {{-- Arqueo --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $cifras = [
                    ['Monto inicial', Config::importe($sesion->monto_inicial), 'text-gray-800 dark:text-white/90', null],
                    ['Vendido', Config::importe($resumen['vendido']), 'text-gray-800 dark:text-white/90',
                        $resumen['ventas'].' venta(s)'.($resumen['anuladas'] ? ', '.$resumen['anuladas'].' anulada(s)' : '')],
                    ['Ingresos / egresos',
                        Config::importe($resumen['ingresos']).' / '.Config::importe($resumen['egresos']),
                        'text-gray-800 dark:text-white/90', 'movimientos de caja'],
                    // Arqueo a ciegas: el esperado lo ve quien cuenta, no quien
                    // tiene el cajón. Ver CajaController::arquea().
                    $veArqueo
                        ? [$abierta ? 'Efectivo esperado' : 'Esperado al cerrar', Config::importe($resumen['esperado']),
                            'text-brand-500 dark:text-brand-400', $abierta ? 'lo que debería haber ahora' : null]
                        : ['Arqueo', $abierta ? 'Al cerrar' : 'Cerrado', 'text-gray-800 dark:text-white/90',
                            'lo cuenta el administrador contigo'],
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
            {{-- Ventas del turno --}}
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="px-6 py-5">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas del turno</h2>
                    </div>

                    <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    @foreach (['Hora', 'Comprobante', 'Cliente', 'Estado', 'Total'] as $columna)
                                        <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                            {{ $columna }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($ventas as $venta)
                                    <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $venta->fecha?->format('H:i') }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <a href="{{ route('ventas.show', $venta) }}"
                                                class="font-mono text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                                {{ $venta->comprobante?->numero_completo ?? '#'.$venta->id }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $venta->cliente?->nombre ?? 'Cliente varios' }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <x-ui.estado :estado="$venta->estado === 'ANULADA' ? 'CESADO' : 'ACTIVO'"
                                                :texto="ucfirst(str_replace('_', ' ', mb_strtolower($venta->estado)))" />
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap text-theme-sm font-medium {{ $venta->estado === 'ANULADA' ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-white/90' }}">
                                            {{ Config::importe($venta->total) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                            Este turno todavía no registró ventas.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-common.paginacion :paginador="$ventas" />
                </div>
            </div>

            {{-- Movimientos --}}
            <x-common.component-card title="Movimientos de caja"
                desc="Entradas y salidas de efectivo que no son ventas.">
                @forelse ($sesion->movimientos->sortByDesc('fecha') as $movimiento)
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <p class="truncate text-theme-sm text-gray-800 dark:text-white/90">{{ $movimiento->concepto }}</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $movimiento->fecha?->format('H:i') }} · {{ $movimiento->usuario?->usuario }}
                            </p>
                        </div>
                        <span class="whitespace-nowrap text-theme-sm font-medium {{ $movimiento->tipo === 'INGRESO' ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                            {{ $movimiento->tipo === 'INGRESO' ? '+' : '−' }}{{ Config::importe($movimiento->monto) }}
                        </span>
                    </div>
                @empty
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Sin movimientos en este turno.</p>
                @endforelse
            </x-common.component-card>
        </div>

        {{-- Registrar movimiento --}}
        @if ($abierta && $esPropia)
            @puede('caja.abrir')
                <div x-show="moviendo" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-movimiento-caja"
                    class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                    <div @click="moviendo = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                    <div x-trap.inert.noscroll="moviendo"
                        class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                        <h2 id="titulo-modal-movimiento-caja" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Movimiento de caja</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            Para el dinero que entra o sale del cajón sin ser una venta: un adelanto, la compra de
                            hielo, un retiro parcial.
                        </p>

                        <form method="POST" action="{{ route('caja.movimiento', $sesion) }}" class="space-y-5">
                            @csrf
                            <input type="hidden" name="tipo" :value="tipo" />

                            <div class="grid grid-cols-2 gap-3">
                                <button type="button" @click="tipo = 'INGRESO'"
                                    :class="tipo === 'INGRESO'
                                        ? 'border-success-500 bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500'
                                        : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                                    class="rounded-xl border-2 px-4 py-3 text-sm font-medium transition">
                                    Ingreso
                                </button>
                                <button type="button" @click="tipo = 'EGRESO'"
                                    :class="tipo === 'EGRESO'
                                        ? 'border-error-500 bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-500'
                                        : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                                    class="rounded-xl border-2 px-4 py-3 text-sm font-medium transition">
                                    Egreso
                                </button>
                            </div>

                            <x-form.campo label="Concepto" for="concepto" name="concepto" required>
                                <x-form.input id="concepto" name="concepto"
                                    placeholder="Compra de bolsas, adelanto a proveedor…" required />
                            </x-form.campo>

                            <x-form.campo label="Monto" for="monto" name="monto" required>
                                <x-form.input id="monto" name="monto" type="number" step="0.01" min="0.01" required />
                            </x-form.campo>

                            <div class="flex justify-end gap-3">
                                <x-ui.button type="button" variant="outline" size="sm" @click="moviendo = false">Cancelar</x-ui.button>
                                <x-ui.button type="submit" size="sm">Registrar</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            @endpuede
        @endif

        {{-- Cerrar caja --}}
        @if ($abierta)
            @puede('caja.cerrar')
                <div x-show="cerrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cerrar-caja"
                    class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                    <div @click="cerrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                    <div x-trap.inert.noscroll="cerrando"
                        class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                        <h2 id="titulo-modal-cerrar-caja" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Cerrar caja</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            Cuenta el efectivo que hay en el cajón. El sistema compara con lo esperado y registra la
                            diferencia: no se corrige, se explica.
                        </p>

                        <form method="POST" action="{{ route('caja.cerrar', $sesion) }}" class="space-y-5">
                            @csrf
                            {{-- El estado del turno cuando se empezó a contar: si al confirmar
                                 ya cambió, el cierre se detiene y pide revisar. --}}
                            <input type="hidden" name="huella" value="{{ $sesion->huella() }}">

                            {{-- El esperado aparece recién después de contar: primero se
                                 cuenta el cajón, después se compara. --}}
                            <div x-show="declarado !== null" x-cloak class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                                <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span>Efectivo esperado</span>
                                    <b class="text-gray-800 dark:text-white/90">{{ Config::importe($resumen['esperado']) }}</b>
                                </div>
                            </div>

                            {{-- Vacío hasta que se cuente de verdad: sin esto, el
                                 campo ya venía igualado al esperado y cerrar sin
                                 tocar nada reportaba «cuadrado» sin contar nada. --}}
                            <x-form.campo label="Efectivo contado" for="monto_declarado" name="monto_declarado" required>
                                <x-form.input id="monto_declarado" name="monto_declarado" type="number" step="0.01"
                                    min="0" placeholder="Cuenta el cajón y escribe lo que hay" x-model.number="declarado"
                                    required autofocus />
                            </x-form.campo>

                            <div x-show="declarado !== null" x-cloak class="rounded-xl p-4"
                                :class="(declarado - esperado) === 0
                                    ? 'bg-success-50 dark:bg-success-500/10'
                                    : 'bg-error-50 dark:bg-error-500/10'">
                                <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Diferencia</p>
                                <p class="text-lg font-semibold"
                                    :class="(declarado - esperado) === 0
                                        ? 'text-success-700 dark:text-success-500'
                                        : 'text-error-600 dark:text-error-500'">
                                    <span x-text="(declarado - esperado) > 0 ? '+' : ''"></span>{{ $moneda ?? Config::moneda() }}
                                    <span x-text="Math.abs(Math.round((declarado - esperado) * 100) / 100).toFixed(2)"></span>
                                </p>
                                <p x-show="(declarado - esperado) < 0" class="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                                    Falta dinero en el cajón. Explica la diferencia abajo.
                                </p>
                            </div>

                            <x-form.campo label="Observación" for="cierre_observacion" name="observacion"
                                help="Obligatoria si hay diferencia: es lo que justifica el descuadre.">
                                <x-form.textarea id="cierre_observacion" name="observacion"
                                    x-bind:required="declarado !== null && Math.round((declarado - esperado) * 100) !== 0"
                                    placeholder="Sin novedad / faltó vuelto de una venta / …" />
                            </x-form.campo>

                            <x-form.campo label="Queda en el cajón para el siguiente turno" for="fondo_dejado" name="fondo_dejado"
                                help="El fondo de cambio que no se retira. El próximo turno de esta caja empieza con este monto.">
                                <x-form.input id="fondo_dejado" name="fondo_dejado" type="number" step="0.01" min="0"
                                    placeholder="Opcional" />
                            </x-form.campo>

                            <div class="flex justify-end gap-3">
                                <x-ui.button type="button" variant="outline" size="sm" @click="cerrando = false">Cancelar</x-ui.button>
                                <x-ui.button type="submit" variant="danger" size="sm">Cerrar caja</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            @endpuede
        @endif
    </div>
@endsection
