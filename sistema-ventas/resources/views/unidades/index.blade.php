@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('unidad_id') ? 'editar' : 'crear'),
        id: @js(old('unidad_id')),
        codigo: @js(old('codigo', '')),
        nombre: @js(old('nombre', '')),
        decimal: @js((bool) old('permite_decimal', false)),
        productos: 0,
        borrando: false,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.codigo = '';
            this.nombre = '';
            this.decimal = false;
            this.abierto = true;
        },
        editar(unidad) {
            this.modo = 'editar';
            this.id = unidad.id;
            this.codigo = unidad.codigo;
            this.nombre = unidad.nombre;
            this.decimal = unidad.decimal;
            this.abierto = true;
        },
        eliminar(unidad) {
            this.id = unidad.id;
            this.codigo = unidad.codigo;
            this.productos = unidad.productos;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                    La unidad dice en qué se vende cada producto. <b>Admite decimales</b> es la parte que importa en el
                    mostrador: 1.5&nbsp;kg de arroz tiene sentido, 1.5&nbsp;unidades de jabón no, y el sistema lo
                    impide al mover stock.
                </p>
                <x-ui.button size="sm" @click="nuevo()">Nueva unidad</x-ui.button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Código</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombre</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cantidades</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Productos</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($unidades as $unidad)
                            @php
                                $datos = [
                                    'id' => $unidad->id,
                                    'codigo' => $unidad->codigo,
                                    'nombre' => $unidad->nombre,
                                    'decimal' => (bool) $unidad->permite_decimal,
                                    'productos' => $unidad->productos_count,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 font-mono font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $unidad->codigo }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $unidad->nombre }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$unidad->permite_decimal ? 'PLAZO_FIJO' : 'PRACTICAS'"
                                        :texto="$unidad->permite_decimal ? 'Admite decimales' : 'Solo enteros'" />
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $unidad->productos_count ?: 'sin productos' }}
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
                                    Todavía no hay unidades de medida.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-unidad"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-unidad" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nueva unidad de medida' : 'Editar unidad de medida'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('unidades.store') }}' : `/unidades/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `unidad_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="unidad_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="Código" for="unidad-codigo" name="codigo" required
                            help="Mayúsculas, sin espacios.">
                            <x-form.input id="unidad-codigo" name="codigo" x-model="codigo" placeholder="CAJA"
                                maxlength="10" required />
                        </x-form.campo>

                        <x-form.campo label="Nombre" for="unidad-nombre" name="nombre" required>
                            <x-form.input id="unidad-nombre" name="nombre" x-model="nombre" placeholder="Caja"
                                required />
                        </x-form.campo>
                    </div>

                    <x-form.check name="permite_decimal" model="decimal"
                        label="Admite cantidades con decimales (kilos, litros, metros)" />

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-unidad"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-unidad" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar unidad</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar la unidad <b x-text="codigo"></b>?
                </p>
                <p x-show="productos > 0" class="mb-6 text-theme-sm text-error-600 dark:text-error-400">
                    La usan <span x-text="productos"></span> producto(s) del catálogo, así que no se puede eliminar.
                    Cámbiales primero la unidad.
                </p>

                <form method="POST" :action="`/unidades/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm" ::disabled="productos > 0">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
