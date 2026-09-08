@extends('layouts.app')

@section('content')
    <div x-data="{ borrando: false, id: null, usuario: '', nombre: '' }"
        @keydown.escape.window="borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Cada cuenta pertenece a un empleado y tiene un rol de acceso. Desactivar la cuenta retira el acceso al
                sistema sin afectar el vínculo laboral de la persona.
            </p>

            <form method="GET" action="{{ route('usuarios.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Usuario o nombre del empleado" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Rol" for="rol">
                    <x-form.select id="rol" name="rol" :value="$filtros['rol']" placeholder="Todos los roles"
                        :opciones="$roles" />
                </x-form.campo>

                <x-form.campo label="Acceso" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Con y sin acceso"
                        :opciones="['ACTIVO' => 'Solo con acceso', 'INACTIVO' => 'Solo sin acceso']" />
                </x-form.campo>

                <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('usuarios.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" :href="route('usuarios.create')">Nueva cuenta</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="usuario" defecto>Usuario</x-tabla.th>
                            <x-tabla.th clave="empleado">Empleado</x-tabla.th>
                            <x-tabla.th clave="rol">Rol</x-tabla.th>
                            <x-tabla.th clave="acceso">Acceso</x-tabla.th>
                            <x-tabla.th clave="ingreso" inicial="desc">Último ingreso</x-tabla.th>
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($usuarios as $cuenta)
                            @php $esPropia = $cuenta->id === auth()->id(); @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.inicial :nombre="$cuenta->empleado?->nombre_completo ?? $cuenta->usuario"
                                            size="sm" />
                                        <div>
                                            <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                                {{ $cuenta->usuario }}
                                            </span>
                                            @if ($esPropia)
                                                <span class="text-theme-xs text-brand-500 dark:text-brand-400">tu cuenta</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                        {{ $cuenta->empleado?->nombre_completo }}
                                    </span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $cuenta->empleado?->cargo?->nombre }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $cuenta->rol?->nombre }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-ui.estado :estado="$cuenta->activo ? 'ACTIVO' : 'CESADO'"
                                            :texto="$cuenta->activo ? 'Habilitado' : 'Deshabilitado'" />
                                        @if ($cuenta->empleado?->estado !== 'ACTIVO')
                                            <x-ui.estado :estado="$cuenta->empleado?->estado"
                                                :texto="'empleado '.mb_strtolower($cuenta->empleado?->estado ?? '')" />
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $cuenta->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('usuarios.edit', $cuenta) }}" title="Editar"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 20h4L19 9a2.8 2.8 0 1 0-4-4L4 16v4Z" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </a>

                                        @unless ($esPropia)
                                            <form method="POST" action="{{ route('usuarios.acceso', $cuenta) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                    title="{{ $cuenta->activo ? 'Quitar acceso' : 'Dar acceso' }}"
                                                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05] {{ $cuenta->activo ? 'hover:text-warning-600' : 'hover:text-success-600' }}">
                                                    @if ($cuenta->activo)
                                                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                            <rect x="4" y="10" width="16" height="11" rx="2"
                                                                stroke="currentColor" stroke-width="1.5" />
                                                            <path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor"
                                                                stroke-width="1.5" stroke-linecap="round" />
                                                        </svg>
                                                    @else
                                                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                            <rect x="4" y="10" width="16" height="11" rx="2"
                                                                stroke="currentColor" stroke-width="1.5" />
                                                            <path d="M8 10V7a4 4 0 0 1 7.5-2" stroke="currentColor"
                                                                stroke-width="1.5" stroke-linecap="round" />
                                                        </svg>
                                                    @endif
                                                </button>
                                            </form>

                                            <button type="button" title="Eliminar"
                                                @click="borrando = true; id = {{ $cuenta->id }}; usuario = @js($cuenta->usuario); nombre = @js($cuenta->empleado?->nombre_completo)"
                                                class="rounded-lg p-2 text-gray-500 transition hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10">
                                                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                    <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"
                                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                        stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay cuentas que coincidan con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$usuarios" />
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-cuenta"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-cuenta" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar cuenta</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar la cuenta <b x-text="usuario"></b> de <span x-text="nombre"></span>?
                </p>
                <p class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Si la cuenta ya registró operaciones, se desactivará en lugar de eliminarse para conservar la
                    trazabilidad. El empleado no se ve afectado.
                </p>

                <form method="POST" :action="`/usuarios/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
