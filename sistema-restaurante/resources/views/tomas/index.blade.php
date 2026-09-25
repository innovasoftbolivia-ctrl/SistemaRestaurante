@extends('layouts.app')

@section('content')
    <div class="space-y-6" x-data="{ abriendo: @js($errors->has('observacion')) }"
        @keydown.escape.window="abriendo = false">

        {{-- Qué es y cómo se hace: la primera vez nadie sabe que el local puede
             seguir vendiendo mientras se cuenta. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-2xl space-y-2">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Contar todas las bebidas de una vez</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Abre una toma, cuenta lo que hay en la bodega y en las heladeras, y escribe cada número en la planilla.
                        Al cerrarla, el sistema ajusta de una vez todas las diferencias y las deja en el kardex.
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        El local puede seguir vendiendo mientras cuentas: lo que se venda después de contar una bebida se
                        respeta al cerrar. Lo que no cuentes queda como estaba.
                    </p>
                </div>

                <div class="shrink-0">
                    @if ($abierta)
                        <x-ui.button :href="route('tomas.show', $abierta)">Seguir con la toma #{{ $abierta->id }}</x-ui.button>
                    @else
                        <x-ui.button type="button" @click="abriendo = true">Nueva toma de inventario</x-ui.button>
                    @endif
                </div>
            </div>
        </div>

        @if ($abierta)
            <x-ui.alert variant="warning" title="Hay una toma en curso"
                message="Abierta el {{ $abierta->fecha_apertura?->format('d/m/Y H:i') }}. Hasta que la cierres o la canceles no se puede abrir otra."
                :show-link="true" :link-href="route('tomas.show', $abierta)" link-text="Continuar la toma" />
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Toma</x-tabla.th>
                            <x-tabla.th>Estado</x-tabla.th>
                            <x-tabla.th derecha>Contados</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Ajustes</x-tabla.th>
                            <x-tabla.th class="hidden md:table-cell">Abierta</x-tabla.th>
                            <x-tabla.th class="hidden lg:table-cell">Cerrada</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($tomas as $toma)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <a href="{{ route('tomas.show', $toma) }}"
                                        class="font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        #{{ $toma->id }}
                                    </a>
                                    @if ($toma->observacion)
                                        <span class="block max-w-56 truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $toma->observacion }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @switch($toma->estado)
                                        @case('ABIERTA')
                                            <x-ui.estado estado="SUSPENDIDO" texto="En curso" />
                                        @break
                                        @case('CERRADA')
                                            <x-ui.estado estado="ACTIVO" texto="Cerrada" />
                                        @break
                                        @default
                                            <x-ui.estado estado="SIN_CUENTA" texto="Cancelada" />
                                    @endswitch
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-800 dark:text-white/90">
                                    {{ number_format($toma->contados_count) }}
                                    <span class="text-gray-400">/ {{ number_format($toma->detalle_count) }}</span>
                                </td>
                                <td class="hidden px-5 py-4 text-right whitespace-nowrap text-theme-sm tabular-nums text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ $toma->estado === 'CERRADA' ? number_format($toma->ajustes_count) : '—' }}
                                </td>
                                <td class="hidden px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $toma->fecha_apertura?->format('d/m/Y H:i') }}
                                    <span class="block text-theme-xs">{{ $toma->usuarioApertura?->usuario }}</span>
                                </td>
                                <td class="hidden px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 lg:table-cell dark:text-gray-400">
                                    @if ($toma->fecha_cierre)
                                        {{ $toma->fecha_cierre->format('d/m/Y H:i') }}
                                        <span class="block text-theme-xs">{{ $toma->usuarioCierre?->usuario }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-0">
                                    <x-common.vacio icono="lista" titulo="Todavía no se hizo ninguna toma de inventario." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$tomas" />
        </div>

        @unless ($abierta)
            <div x-show="abriendo" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-toma"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="abriendo = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="abriendo"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-toma" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Nueva toma de inventario
                    </h2>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Se arma la planilla con todo lo que lleva inventario. El stock no cambia hasta que cierres la toma.
                    </p>

                    <form method="POST" action="{{ route('tomas.store') }}" class="space-y-5">
                        @csrf

                        <x-form.campo label="Observación" for="toma-observacion" name="observacion">
                            <x-form.input id="toma-observacion" name="observacion" maxlength="255"
                                placeholder="Conteo de fin de mes" />
                        </x-form.campo>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="abriendo = false">Cancelar</x-ui.button>
                            <x-ui.button type="submit" size="sm">Abrir toma</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endunless
    </div>
@endsection
