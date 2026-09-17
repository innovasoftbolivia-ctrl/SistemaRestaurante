@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('categoria_id') ? 'editar' : 'crear'),
        id: @js(old('categoria_id')),
        nombre: @js(old('nombre', '')),
        descripcion: @js(old('descripcion', '')),
        activo: @js((bool) old('activo', true)),
        productos: 0,
        borrando: false,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.nombre = '';
            this.descripcion = '';
            this.activo = true;
            this.abierto = true;
        },
        editar(categoria) {
            this.modo = 'editar';
            this.id = categoria.id;
            this.nombre = categoria.nombre;
            this.descripcion = categoria.descripcion ?? '';
            this.activo = categoria.activo;
            this.abierto = true;
        },
        eliminar(categoria) {
            this.id = categoria.id;
            this.nombre = categoria.nombre;
            this.productos = categoria.productos;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Las categorías agrupan el catálogo para buscar y para los reportes de venta por rubro.
            </p>

            <form method="GET" action="{{ route('categorias.index') }}"
                class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$buscar" placeholder="Nombre de la categoría" />
                    </x-form.campo>
                </div>
                <div class="flex gap-2">
                    <x-ui.button type="submit" size="sm">Buscar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('categorias.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" @click="nuevo()">Nueva categoría</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Descripción</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Productos</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($categorias as $categoria)
                            @php
                                $datos = [
                                    'id' => $categoria->id,
                                    'nombre' => $categoria->nombre,
                                    'descripcion' => $categoria->descripcion,
                                    'activo' => (bool) $categoria->activo,
                                    'productos' => $categoria->productos_count,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4 font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $categoria->nombre }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $categoria->descripcion ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    @if ($categoria->productos_count)
                                        <a href="{{ route('productos.index', ['categoria' => $categoria->id]) }}"
                                            class="hover:text-brand-500">
                                            <b class="text-gray-800 dark:text-white/90">{{ $categoria->productos_activos_count }}</b>
                                            en catálogo de {{ $categoria->productos_count }}
                                        </a>
                                    @else
                                        sin productos
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$categoria->activo ? 'ACTIVO' : 'CESADO'"
                                        :texto="$categoria->activo ? 'Activa' : 'Inactiva'" />
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
                                        @puede('registros.eliminar')
                                        <button type="button" title="Eliminar" @click="eliminar(@js($datos))"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                        @endpuede
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay categorías que coincidan con la búsqueda.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-categoria"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-categoria" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nueva categoría' : 'Editar categoría'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('categorias.store') }}' : `/categorias/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `categoria_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="categoria_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <x-form.campo label="Nombre" for="categoria-nombre" name="nombre" required>
                        <x-form.input id="categoria-nombre" name="nombre" x-model="nombre" placeholder="Abarrotes"
                            required />
                    </x-form.campo>

                    <x-form.campo label="Descripción" for="categoria-descripcion" name="descripcion">
                        <x-form.textarea id="categoria-descripcion" name="descripcion" x-model="descripcion"
                            placeholder="Qué tipo de productos agrupa" />
                    </x-form.campo>

                    @puede('registros.eliminar')
                    <x-form.check name="activo" model="activo" label="Categoría disponible para asignar" />
                    @endpuede

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-categoria"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-categoria" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar categoría</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar la categoría <b x-text="nombre"></b>?
                </p>
                <p x-show="productos > 0" class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Tiene <span x-text="productos"></span> producto(s), así que se desactivará en lugar de eliminarse.
                </p>

                <form method="POST" :action="`/categorias/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
