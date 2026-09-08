@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div x-data="{ abriendo: {{ $errors->any() && old('monto_inicial') !== null ? 'true' : 'false' }} }"
        @keydown.escape.window="abriendo = false" class="space-y-6">

        {{-- Turno propio --}}
        @if ($sesion)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ $sesion->caja?->nombre }}
                            </h2>
                            <x-ui.estado estado="ACTIVO" texto="Turno abierto" />
                        </div>
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Desde el {{ $sesion->fecha_apertura?->format('d/m/Y H:i') }} ·
                            monto inicial {{ Config::importe($sesion->monto_inicial) }} ·
                            {{ $sesion->ventas_count }} venta(s)
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @puede('ventas.registrar')
                            <x-ui.button size="sm" :href="route('pos.index')">Ir al mostrador</x-ui.button>
                        @endpuede
                        <x-ui.button size="sm" variant="outline" :href="route('caja.show', $sesion)">
                            Ver turno y cerrar
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @else
            <x-common.component-card title="No tienes una caja abierta"
                desc="Cada venta se imputa a un turno. Abre el tuyo con el efectivo con el que empiezas.">
                @puede('caja.abrir')
                    @if ($cajas->isEmpty())
                        <x-ui.alert variant="warning" title="No hay cajas configuradas"
                            message="Registra al menos una caja en la base de datos para poder abrir turno." />
                    @else
                        <x-ui.button @click="abriendo = true">Abrir caja</x-ui.button>
                    @endif
                @else
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Tu rol no permite abrir caja.
                    </p>
                @endpuede
            </x-common.component-card>
        @endif

        {{-- Estado de cada caja --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cajas as $caja)
                @php $abierta = $caja->sesionAbierta; @endphp
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <div>
                            <h4 class="font-medium text-gray-800 dark:text-white/90">{{ $caja->nombre }}</h4>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $caja->ubicacion ?: '—' }}</p>
                        </div>
                        <x-ui.estado :estado="$abierta ? 'ACTIVO' : 'SIN_CUENTA'"
                            :texto="$abierta ? 'Abierta' : 'Cerrada'" />
                    </div>

                    @if ($abierta)
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Turno de <b class="text-gray-800 dark:text-white/90">{{ $abierta->usuarioApertura?->usuario }}</b>
                            desde las {{ $abierta->fecha_apertura?->format('H:i') }}
                        </p>
                        <a href="{{ route('caja.show', $abierta) }}"
                            class="mt-2 inline-block text-theme-xs text-brand-500 dark:text-brand-400 hover:text-brand-600">Ver turno →</a>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Disponible para abrir.</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Historial --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Turnos anteriores</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Toda apertura y todo cierre quedan registrados con su responsable y su diferencia.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Caja', 'Abierto por', 'Apertura', 'Cierre', 'Ventas', 'Esperado', 'Contado', 'Diferencia'] as $columna)
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($historial as $turno)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <a href="{{ route('caja.show', $turno) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $turno->caja?->nombre }}
                                    </a>
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $turno->usuarioApertura?->usuario }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $turno->fecha_apertura?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                    @if ($turno->estaAbierta())
                                        <x-ui.estado estado="ACTIVO" texto="En curso" />
                                    @else
                                        {{ $turno->fecha_cierre?->format('d/m/Y H:i') }}
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $turno->ventas_count }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $turno->monto_esperado !== null ? Config::importe($turno->monto_esperado) : '—' }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $turno->monto_declarado !== null ? Config::importe($turno->monto_declarado) : '—' }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    @if ($turno->diferencia === null)
                                        <span class="text-theme-sm text-gray-400">—</span>
                                    @else
                                        @php $dif = (float) $turno->diferencia; @endphp
                                        <span class="text-theme-sm font-medium {{ $dif == 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                                            {{ $dif > 0 ? '+' : '' }}{{ Config::importe($dif) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Todavía no se ha abierto ninguna caja.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$historial" />
        </div>

        {{-- Abrir caja --}}
        @puede('caja.abrir')
            <div x-show="abriendo" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-abrir-caja"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="abriendo = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="abriendo"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-abrir-caja" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Abrir caja</h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Cuenta el efectivo con el que empiezas el turno. De ahí parte el arqueo al cerrar.
                    </p>

                    <form method="POST" action="{{ route('caja.abrir') }}" class="space-y-5">
                        @csrf

                        <x-form.campo label="Caja" for="caja_id" name="caja_id" required>
                            <x-form.select id="caja_id" name="caja_id" :value="old('caja_id')"
                                placeholder="Selecciona una caja"
                                :opciones="$cajas->filter(fn ($c) => ! $c->sesionAbierta)->pluck('nombre', 'id')" required />
                        </x-form.campo>

                        <x-form.campo label="Monto inicial" for="monto_inicial" name="monto_inicial" required
                            help="El efectivo que hay en el cajón al empezar.">
                            <x-form.input id="monto_inicial" name="monto_inicial" type="number" step="0.01" min="0"
                                :value="old('monto_inicial', '0.00')" required autofocus />
                        </x-form.campo>

                        <x-form.campo label="Observación" for="observacion" name="observacion">
                            <x-form.input id="observacion" name="observacion" :value="old('observacion')"
                                placeholder="Opcional" />
                        </x-form.campo>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="abriendo = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Abrir caja</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endpuede
    </div>
@endsection
