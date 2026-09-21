@extends('layouts.app')

@section('content')
    <div class="space-y-6">

        {{-- Filtros --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('empleados.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Nombre, documento o correo" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Estado" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']"
                        placeholder="Todos los estados" :opciones="array_combine($estados, array_map(fn ($e) => ucfirst(mb_strtolower($e)), $estados))" />
                </x-form.campo>

                <x-form.campo label="Cargo" for="cargo">
                    <x-form.select id="cargo" name="cargo" :value="$filtros['cargo']" placeholder="Todos los cargos"
                        :opciones="$cargos" />
                </x-form.campo>

                <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('empleados.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" :href="route('empleados.create')"
                        :start-icon="'<svg aria-hidden=\'true\' width=\'18\' height=\'18\' viewBox=\'0 0 24 24\' fill=\'none\'><path d=\'M12 5v14M5 12h14\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\'/></svg>'">
                        Nuevo empleado
                    </x-ui.button>
                </div>
            </form>
        </div>

        {{-- Tabla --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="nombre" defecto>Empleado</x-tabla.th>
                            <x-tabla.th clave="documento">Documento</x-tabla.th>
                            <x-tabla.th clave="cargo">Cargo</x-tabla.th>
                            <x-tabla.th clave="contrato">Contrato</x-tabla.th>
                            <x-tabla.th clave="ingreso" inicial="desc">Ingreso</x-tabla.th>
                            {{-- «Cuenta» no se ordena: mezcla tres cosas (si hay
                                 cuenta, su nombre y si está activa). --}}
                            <x-tabla.th>Cuenta</x-tabla.th>
                            <x-tabla.th clave="estado">Estado</x-tabla.th>
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($empleados as $empleado)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.inicial :nombre="$empleado->nombre_completo" size="sm" />
                                        <div>
                                            <a href="{{ route('empleados.show', $empleado) }}"
                                                class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                                {{ $empleado->nombre_completo }}
                                            </a>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $empleado->email ?? $empleado->telefono ?? 'sin contacto' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $empleado->tipo_documento }} {{ $empleado->documento }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $empleado->cargo?->nombre }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$empleado->tipo_contrato" />
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $empleado->fecha_ingreso?->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-4">
                                    @if ($empleado->usuario)
                                        <x-ui.estado :estado="$empleado->usuario->activo ? 'ACTIVO' : 'CESADO'"
                                            :texto="$empleado->usuario->usuario" />
                                    @else
                                        <x-ui.estado estado="SIN_CUENTA" />
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$empleado->estado" />
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('empleados.show', $empleado) }}" title="Ver ficha"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z"
                                                    stroke="currentColor" stroke-width="1.5" />
                                                <circle cx="12" cy="12" r="2.5" stroke="currentColor"
                                                    stroke-width="1.5" />
                                            </svg>
                                        </a>
                                        <a href="{{ route('empleados.edit', $empleado) }}" title="Editar"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 20h4L19 9a2.8 2.8 0 1 0-4-4L4 16v4Z" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </a>

                                        @if ($empleado->estado === 'ACTIVO')
                                            <button type="button" title="Registrar cese"
                                                @click="$dispatch('abrir-cese', {
                                                    id: {{ $empleado->id }},
                                                    nombre: @js($empleado->nombre_completo),
                                                    ingreso: @js($empleado->fecha_ingreso?->format('Y-m-d'))
                                                })"
                                                class="rounded-lg p-2 text-gray-500 transition hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10">
                                                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                    <path d="M17 16l4-4m0 0l-4-4m4 4H9" stroke="currentColor"
                                                        stroke-width="1.5" stroke-linecap="round"
                                                        stroke-linejoin="round" />
                                                    <path d="M13 8V7a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3v-1"
                                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                                </svg>
                                            </button>
                                        @else
                                            <form method="POST" action="{{ route('empleados.reactivar', $empleado) }}">
                                                @csrf
                                                <button type="submit" title="Reactivar"
                                                    class="rounded-lg p-2 text-gray-500 transition hover:bg-success-50 hover:text-success-600 dark:text-gray-400 dark:hover:bg-success-500/10">
                                                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                        <path d="M20 11a8 8 0 1 0-2.3 5.6" stroke="currentColor"
                                                            stroke-width="1.5" stroke-linecap="round" />
                                                        <path d="M20 5v6h-6" stroke="currentColor" stroke-width="1.5"
                                                            stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No se encontraron empleados con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$empleados" />
        </div>
    </div>

    {{-- Registrar cese --}}
    <div x-data="{ abierto: false, id: null, nombre: '', ingreso: '' }"
        @abrir-cese.window="abierto = true; id = $event.detail.id; nombre = $event.detail.nombre; ingreso = $event.detail.ingreso"
        @keydown.escape.window="abierto = false">
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cese"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-cese" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Registrar cese</h2>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    Al cesar a <b x-text="nombre"></b> su cuenta del sistema queda sin acceso automáticamente.
                    El empleado no se borra: su historial se conserva.
                </p>

                <form method="POST" :action="`/empleados/${id}`" class="space-y-5">
                    @csrf
                    @method('DELETE')

                    <x-form.campo label="Fecha de cese" for="fecha_cese" name="fecha_cese" required>
                        <x-form.input id="fecha_cese" name="fecha_cese" type="date"
                            :value="now()->format('Y-m-d')" ::min="ingreso" required />
                    </x-form.campo>

                    <x-form.campo label="Motivo" for="motivo_cese" name="motivo_cese" required>
                        <x-form.textarea id="motivo_cese" name="motivo_cese"
                            placeholder="Renuncia voluntaria, fin de contrato…" required />
                    </x-form.campo>

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">
                            Cancelar
                        </x-ui.button>
                        <x-ui.button type="submit" variant="danger" size="sm">Registrar cese</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
