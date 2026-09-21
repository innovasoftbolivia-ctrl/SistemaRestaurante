@extends('layouts.app')

@section('content')
    @php
        $totalPermisos = $permisosPorModulo->flatten()->count();
    @endphp

    {{-- Si la validación falló, el modal se reabre con lo que se había marcado. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('rol_id') ? 'editar' : 'crear'),
        id: @js(old('rol_id')),
        nombre: @js(old('nombre', '')),
        descripcion: @js(old('descripcion', '')),
        activo: @js((bool) old('activo', true)),
        permisos: @js(array_map('intval', (array) old('permisos', []))),
        borrando: false,
        usuarios: 0,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.nombre = '';
            this.descripcion = '';
            this.activo = true;
            this.permisos = [];
            this.abierto = true;
        },
        editar(rol) {
            this.modo = 'editar';
            this.id = rol.id;
            this.nombre = rol.nombre;
            this.descripcion = rol.descripcion ?? '';
            this.activo = rol.activo;
            this.permisos = rol.permisos;
            this.abierto = true;
        },
        eliminar(rol) {
            this.id = rol.id;
            this.nombre = rol.nombre;
            this.usuarios = rol.usuarios;
            this.borrando = true;
        },
        alternarModulo(ids) {
            const todos = ids.every(id => this.permisos.includes(id));
            this.permisos = todos
                ? this.permisos.filter(id => !ids.includes(id))
                : [...new Set([...this.permisos, ...ids])];
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                    El rol define qué puede hacer una cuenta dentro del sistema, y es independiente del cargo laboral:
                    el gerente puede tener rol Administrador, y un cajero de confianza también.
                    Hay {{ $totalPermisos }} permisos disponibles.
                </p>
                <x-ui.button size="sm" @click="nuevo()">Nuevo rol</x-ui.button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 2xl:grid-cols-3">
            @foreach ($roles as $rol)
                @php
                    $datos = [
                        'id' => $rol->id,
                        'nombre' => $rol->nombre,
                        'descripcion' => $rol->descripcion,
                        'activo' => (bool) $rol->activo,
                        'permisos' => $rol->permisos->pluck('id')->all(),
                        'usuarios' => $rol->usuarios_count,
                    ];
                @endphp

                <div class="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $rol->nombre }}</h2>
                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $rol->descripcion ?: '—' }}</p>
                        </div>
                        <x-ui.estado :estado="$rol->activo ? 'ACTIVO' : 'CESADO'"
                            :texto="$rol->activo ? 'Activo' : 'Inactivo'" />
                    </div>

                    <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                        <b class="text-gray-800 dark:text-white/90">{{ $rol->usuarios_activos_count }}</b>
                        cuenta(s) con acceso de {{ $rol->usuarios_count }} asignada(s)
                    </p>

                    <div class="mb-5 flex flex-wrap gap-1.5">
                        @forelse ($rol->permisos as $permiso)
                            <span
                                class="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-theme-xs text-gray-600 dark:bg-white/[0.05] dark:text-gray-400">
                                {{ $permiso->codigo }}
                            </span>
                        @empty
                            <span class="text-theme-xs text-warning-700 dark:text-orange-400">Sin permisos asignados</span>
                        @endforelse
                    </div>

                    {{-- Botones sueltos y no <x-ui.button>: dentro de un atributo
                         de componente Blade la directiva @js no se compila. --}}
                    <div class="mt-auto flex gap-2">
                        <button type="button" @click="editar(@js($datos))"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white transition hover:bg-brand-600">
                            Editar
                        </button>
                        <button type="button" @click="eliminar(@js($datos))"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            Eliminar
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-rol"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-rol" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nuevo rol' : `Editar rol: ${nombre}`"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('roles.store') }}' : `/roles/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `rol_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="rol_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="Nombre" for="rol-nombre" name="nombre" required>
                            <x-form.input id="rol-nombre" name="nombre" x-model="nombre" placeholder="Supervisor"
                                required />
                        </x-form.campo>

                        <x-form.campo label="Descripción" for="rol-descripcion" name="descripcion">
                            <x-form.input id="rol-descripcion" name="descripcion" x-model="descripcion"
                                placeholder="Qué alcance tiene este rol" />
                        </x-form.campo>
                    </div>

                    <div>
                        <p class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-400">Permisos</p>

                        <div class="space-y-3">
                            @foreach ($permisosPorModulo as $modulo => $permisos)
                                @php $ids = $permisos->pluck('id')->all(); @endphp
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                    <div class="mb-3 flex items-center justify-between">
                                        <b class="text-theme-sm text-gray-800 dark:text-white/90">{{ $modulo }}</b>
                                        <button type="button" @click="alternarModulo(@js(array_values($ids)))"
                                            class="text-theme-xs text-brand-500 dark:text-brand-400 hover:text-brand-600">
                                            Alternar módulo
                                        </button>
                                    </div>

                                    <div class="space-y-2">
                                        @foreach ($permisos as $permiso)
                                            <label class="flex cursor-pointer items-start gap-3 select-none">
                                                <input type="checkbox" name="permisos[]" value="{{ $permiso->id }}"
                                                    x-model.number="permisos"
                                                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-500 dark:text-brand-400 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900" />
                                                <span class="text-theme-sm text-gray-700 dark:text-gray-400">
                                                    {{ $permiso->descripcion }}
                                                    <span class="block font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                                        {{ $permiso->codigo }}
                                                    </span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

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
                        Rol disponible para asignar
                    </label>

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-rol"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-rol" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar rol</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar el rol <b x-text="nombre"></b>?
                </p>
                <p x-show="usuarios > 0" class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Tiene <span x-text="usuarios"></span> cuenta(s) asignada(s), así que se desactivará en lugar de
                    eliminarse.
                </p>

                <form method="POST" :action="`/roles/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
