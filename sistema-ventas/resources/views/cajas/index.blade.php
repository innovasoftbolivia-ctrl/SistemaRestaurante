@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('caja_id') ? 'editar' : 'crear'),
        id: @js(old('caja_id')),
        nombre: @js(old('nombre', '')),
        ubicacion: @js(old('ubicacion', '')),
        activo: @js((bool) old('activo', true)),
        turnos: 0,
        enUso: false,
        borrando: false,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.nombre = '';
            this.ubicacion = '';
            this.activo = true;
            this.abierto = true;
        },
        editar(caja) {
            this.modo = 'editar';
            this.id = caja.id;
            this.nombre = caja.nombre;
            this.ubicacion = caja.ubicacion;
            this.activo = caja.activo;
            this.enUso = caja.enUso;
            this.abierto = true;
        },
        eliminar(caja) {
            this.id = caja.id;
            this.nombre = caja.nombre;
            this.turnos = caja.turnos;
            this.enUso = caja.enUso;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                    Los puestos de cobro del local. Cada turno de caja se abre sobre uno de estos, y solo puede haber
                    <b>un turno abierto por caja</b> a la vez. Una caja con turnos ya registrados no se elimina: se
                    desactiva, para no romper el historial de arqueos.
                </p>
                <x-ui.button size="sm" @click="nuevo()">Nueva caja</x-ui.button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombre</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ubicación</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Turnos</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($cajas as $caja)
                            @php
                                $enUso = (bool) $caja->sesionAbierta;
                                $datos = [
                                    'id' => $caja->id,
                                    'nombre' => $caja->nombre,
                                    'ubicacion' => $caja->ubicacion ?? '',
                                    'activo' => (bool) $caja->activo,
                                    'turnos' => $caja->sesiones_count,
                                    'enUso' => $enUso,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $caja->nombre }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $caja->ubicacion ?: '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    @if ($enUso)
                                        <x-ui.estado estado="ACTIVO"
                                            :texto="'Turno abierto · '.($caja->sesionAbierta->usuarioApertura?->usuario ?? '')" />
                                    @else
                                        <x-ui.estado :estado="$caja->activo ? 'PLAZO_FIJO' : 'SIN_CUENTA'"
                                            :texto="$caja->activo ? 'Disponible' : 'Desactivada'" />
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $caja->sesiones_count ?: 'sin turnos' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" title="Editar" @click="editar(@js($datos))"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 20h4L19 9a2.8 2.8 0 1 0-4-4L4 16v4Z" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                        <button type="button" title="Dar de baja" @click="eliminar(@js($datos))"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Todavía no hay cajas. Sin al menos una, nadie puede abrir turno ni cobrar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-caja"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-caja" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nueva caja' : 'Editar caja'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('cajas.store') }}' : `/cajas/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `caja_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="caja_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <x-form.campo label="Nombre" for="caja-nombre" name="nombre" required
                        help="Como lo llaman en el local: «Caja 1», «Mostrador», «Caja rápida».">
                        <x-form.input id="caja-nombre" name="nombre" x-model="nombre" placeholder="Caja 2"
                            maxlength="40" required />
                    </x-form.campo>

                    <x-form.campo label="Ubicación" for="caja-ubicacion" name="ubicacion"
                        help="Opcional. Ayuda a distinguirlas cuando hay varias.">
                        <x-form.input id="caja-ubicacion" name="ubicacion" x-model="ubicacion"
                            placeholder="Mostrador principal" maxlength="60" />
                    </x-form.campo>

                    <x-form.check name="activo" model="activo"
                        label="Disponible para abrir turno" />

                    <p x-show="modo === 'editar' && enUso" x-cloak
                        class="text-theme-xs text-warning-700 dark:text-orange-400">
                        Esta caja tiene un turno abierto ahora mismo: no se puede desactivar hasta que se cierre.
                    </p>

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-caja"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-caja" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">
                    Dar de baja la caja
                </h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Dar de baja <b x-text="nombre"></b>?
                </p>
                <p x-show="turnos > 0 && !enUso" x-cloak class="mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                    Tiene <span x-text="turnos"></span> turno(s) registrados, así que se <b>desactiva</b> en lugar de
                    eliminarse: los arqueos ya firmados siguen en el historial.
                </p>
                <p x-show="enUso" x-cloak class="mb-6 text-theme-sm text-error-600 dark:text-error-400">
                    Tiene un turno abierto ahora mismo. Ciérralo antes de darla de baja.
                </p>

                <form method="POST" :action="`/cajas/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm" ::disabled="enUso">Dar de baja</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
