@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('proveedor_id') ? 'editar' : 'crear'),
        id: @js(old('proveedor_id')),
        f: {
            razon_social: @js(old('razon_social', '')),
            documento: @js(old('documento', '')),
            telefono: @js(old('telefono', '')),
            email: @js(old('email', '')),
            direccion: @js(old('direccion', '')),
        },
        activo: @js((bool) old('activo', true)),
        compras: 0,
        borrando: false,

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.f = { razon_social: '', documento: '', telefono: '', email: '', direccion: '' };
            this.activo = true;
            this.abierto = true;
        },
        editar(p) {
            this.modo = 'editar';
            this.id = p.id;
            this.f = {
                razon_social: p.razon_social,
                documento: p.documento ?? '',
                telefono: p.telefono ?? '',
                email: p.email ?? '',
                direccion: p.direccion ?? '',
            };
            this.activo = p.activo;
            this.abierto = true;
        },
        eliminar(p) {
            this.id = p.id;
            this.f.razon_social = p.razon_social;
            this.compras = p.compras;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                A quién se le compran las bebidas y lo demás que se vende hecho. Un proveedor con compras
                registradas no se elimina: se <b>desactiva</b>, y sus facturas siguen consultándose.
            </p>

            <form method="GET" action="{{ route('proveedores.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Razón social o NIT" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Estado" for="estado">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Todos"
                        :opciones="['activos' => 'Activos', 'inactivos' => 'Inactivos']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('proveedores.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" @click="nuevo()">Nuevo proveedor</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="proveedor" defecto>Proveedor</x-tabla.th>
                            <x-tabla.th clave="documento">NIT</x-tabla.th>
                            <x-tabla.th clave="contacto">Contacto</x-tabla.th>
                            <x-tabla.th clave="compras" inicial="desc">Compras</x-tabla.th>
                            <x-tabla.th>Estado</x-tabla.th>
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($proveedores as $proveedor)
                            @php
                                $datos = [
                                    'id' => $proveedor->id,
                                    'razon_social' => $proveedor->razon_social,
                                    'documento' => $proveedor->documento,
                                    'telefono' => $proveedor->telefono,
                                    'email' => $proveedor->email,
                                    'direccion' => $proveedor->direccion,
                                    'activo' => (bool) $proveedor->activo,
                                    'compras' => $proveedor->compras_count,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.inicial :nombre="$proveedor->razon_social" size="sm" />
                                        <div>
                                            <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                                {{ $proveedor->razon_social }}
                                            </span>
                                            @if ($proveedor->direccion)
                                                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                                    {{ $proveedor->direccion }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap font-mono text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $proveedor->documento ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ collect([$proveedor->telefono, $proveedor->email])->filter()->implode(' · ') ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    @if ($proveedor->compras_count)
                                        <a href="{{ route('compras.index', ['proveedor' => $proveedor->id]) }}"
                                            class="hover:text-brand-500">
                                            <b class="text-gray-800 dark:text-white/90">{{ $proveedor->compras_count }}</b>
                                            {{ $proveedor->compras_count === 1 ? 'compra' : 'compras' }}
                                        </a>
                                    @else
                                        sin compras
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.estado :estado="$proveedor->activo ? 'ACTIVO' : 'CESADO'"
                                        :texto="$proveedor->activo ? 'Activo' : 'Inactivo'" />
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" title="Editar" aria-label="Editar {{ $proveedor->razon_social }}"
                                            @click="editar(@js($datos))"
                                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                                                <path d="M4 20h4L19 9a2.8 2.8 0 1 0-4-4L4 16v4Z" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                        @puede('registros.eliminar')
                                        <button type="button" title="Eliminar" aria-label="Eliminar {{ $proveedor->razon_social }}"
                                            @click="eliminar(@js($datos))"
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
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay proveedores con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$proveedores" />
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-proveedor"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-proveedor" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nuevo proveedor' : 'Editar proveedor'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('proveedores.store') }}' : `{{ url('proveedores') }}/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `proveedor_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="proveedor_id" :value="modo === 'editar' ? id : ''" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-form.campo label="Razón social" for="proveedor-razon" name="razon_social" required>
                                <x-form.input id="proveedor-razon" name="razon_social" x-model="f.razon_social"
                                    placeholder="Distribuidora del Oriente S.R.L." required maxlength="120" />
                            </x-form.campo>
                        </div>

                        <x-form.campo label="NIT" for="proveedor-nit" name="documento">
                            <x-form.input id="proveedor-nit" name="documento" x-model="f.documento" inputmode="numeric"
                                placeholder="1023456027" maxlength="20" />
                        </x-form.campo>

                        <x-form.campo label="Teléfono" for="proveedor-telefono" name="telefono">
                            <x-form.input id="proveedor-telefono" name="telefono" x-model="f.telefono"
                                placeholder="70012345" maxlength="30" />
                        </x-form.campo>

                        <div class="sm:col-span-2">
                            <x-form.campo label="Correo" for="proveedor-email" name="email">
                                <x-form.input id="proveedor-email" name="email" type="email" x-model="f.email"
                                    placeholder="pedidos@distribuidora.com" maxlength="120" />
                            </x-form.campo>
                        </div>

                        <div class="sm:col-span-2">
                            <x-form.campo label="Dirección" for="proveedor-direccion" name="direccion">
                                <x-form.input id="proveedor-direccion" name="direccion" x-model="f.direccion"
                                    placeholder="Av. Grigotá 1200, Santa Cruz" maxlength="200" />
                            </x-form.campo>
                        </div>
                    </div>

                    @puede('registros.eliminar')
                    <x-form.check name="activo" model="activo" label="Proveedor disponible para registrar compras" />
                    @endpuede

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-proveedor"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-proveedor" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar proveedor</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar a <b x-text="f.razon_social"></b>?
                </p>
                <p x-show="compras > 0" class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Tiene <span x-text="compras"></span> compra(s) registrada(s), así que se desactivará en lugar de
                    eliminarse: sus facturas lo referencian.
                </p>

                <form method="POST" :action="`{{ url('proveedores') }}/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
