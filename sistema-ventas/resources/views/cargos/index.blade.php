@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('cargo_id') ? 'editar' : 'crear'),
        id: @js(old('cargo_id')),
        nombre: @js(old('nombre', '')),
        descripcion: @js(old('descripcion', '')),
        activo: @js((bool) old('activo', true)),
        empleados: 0,
        borrando: false,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.nombre = '';
            this.descripcion = '';
            this.activo = true;
            this.borrando = false;
            this.abierto = true;
        },
        editar(cargo) {
            this.modo = 'editar';
            this.id = cargo.id;
            this.nombre = cargo.nombre;
            this.descripcion = cargo.descripcion ?? '';
            this.activo = cargo.activo;
            this.borrando = false;
            this.abierto = true;
        },
        eliminar(cargo) {
            this.id = cargo.id;
            this.nombre = cargo.nombre;
            this.empleados = cargo.empleados;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                El cargo es la función laboral de la persona en el negocio. No define lo que puede hacer dentro del
                sistema: eso lo determina el <b>rol</b> de su cuenta.
            </p>

            <form method="GET" action="{{ route('cargos.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$buscar" placeholder="Nombre del cargo" />
                    </x-form.campo>
                </div>
                <div class="flex gap-2">
                    <x-ui.button type="submit" size="sm">Buscar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('cargos.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" @click="nuevo()">Nuevo cargo</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cargo</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Descripción</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Empleados</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($cargos as $cargo)
                            @php
                                $datos = [
                                    'id' => $cargo->id,
                                    'nombre' => $cargo->nombre,
                                    'descripcion' => $cargo->descripcion,
                                    'activo' => (bool) $cargo->activo,
                                    'empleados' => $cargo->empleados_count,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $cargo->nombre }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $cargo->descripcion ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <b class="text-gray-800 dark:text-white/90">{{ $cargo->empleados_activos_count }}</b>
                                    activos de {{ $cargo->empleados_count }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$cargo->activo ? 'ACTIVO' : 'CESADO'"
                                        :texto="$cargo->activo ? 'Activo' : 'Inactivo'" />
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
                                        <button type="button" title="Eliminar" @click="eliminar(@js($datos))"
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
                                    No hay cargos que coincidan con la búsqueda.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cargo"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-cargo" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nuevo cargo' : 'Editar cargo'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('cargos.store') }}' : `/cargos/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `cargo_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="cargo_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <x-form.campo label="Nombre" for="cargo-nombre" name="nombre" required>
                        <x-form.input id="cargo-nombre" name="nombre" x-model="nombre" placeholder="Cajero" required />
                    </x-form.campo>

                    <x-form.campo label="Descripción" for="cargo-descripcion" name="descripcion"
                        help="Qué hace esta persona en el negocio.">
                        <x-form.textarea id="cargo-descripcion" name="descripcion" x-model="descripcion" />
                    </x-form.campo>

                    <label class="flex cursor-pointer items-center text-sm text-gray-700 select-none dark:text-gray-400">
                        <span class="relative">
                            <input type="hidden" name="activo" :value="activo ? '1' : '0'" />
                            <input type="checkbox" class="sr-only" :checked="activo" @change="activo = !activo" />
                            <span
                                :class="activo ? 'border-brand-500 bg-brand-500' : 'bg-transparent border-gray-300 dark:border-gray-700'"
                                class="mr-3 flex h-5 w-5 items-center justify-center rounded-md border-[1.25px]">
                                <span :class="activo ? '' : 'opacity-0'">
                                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                        <path d="M11.6666 3.5L5.24992 9.91667L2.33325 7" stroke="white"
                                            stroke-width="1.94437" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                            </span>
                        </span>
                        Cargo disponible para asignar
                    </label>

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-cargo"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-cargo" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar cargo</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar el cargo <b x-text="nombre"></b>?
                </p>
                <p x-show="empleados > 0" class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Tiene <span x-text="empleados"></span> empleado(s) asignado(s), así que se desactivará en lugar de
                    eliminarse para no romper el histórico.
                </p>

                <form method="POST" :action="`/cargos/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
