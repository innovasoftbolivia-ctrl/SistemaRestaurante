@extends('layouts.app')

@section('content')
    {{-- Si la validación falló, el modal se reabre con lo que se había escrito. --}}
    <div x-data="{
        abierto: @js($errors->any()),
        modo: @js(old('cliente_id') ? 'editar' : 'crear'),
        id: @js(old('cliente_id')),
        persona: @js(old('tipo_persona', 'NATURAL')),
        f: {
            tipo_documento: @js(old('tipo_documento', 'CI')),
            documento: @js(old('documento', '')),
            nombres: @js(old('nombres', '')),
            apellidos: @js(old('apellidos', '')),
            razon_social: @js(old('razon_social', '')),
            nombre_comercial: @js(old('nombre_comercial', '')),
            representante_legal: @js(old('representante_legal', '')),
            direccion: @js(old('direccion', '')),
            telefono: @js(old('telefono', '')),
            email: @js(old('email', '')),
        },
        activo: @js((bool) old('activo', true)),
        ventas: 0,
        borrando: false,

        get juridica() { return this.persona === 'JURIDICA'; },

        /* La persona jurídica siempre va con NIT: es lo que exige la factura. */
        cambiarPersona(valor) {
            this.persona = valor;
            this.f.tipo_documento = valor === 'JURIDICA' ? 'NIT' : 'CI';
        },

        nuevo() {
            this.modo = 'crear';
            this.id = null;
            this.persona = 'NATURAL';
            this.f = { tipo_documento: 'CI', documento: '', nombres: '', apellidos: '',
                       razon_social: '', nombre_comercial: '', representante_legal: '',
                       direccion: '', telefono: '', email: '' };
            this.activo = true;
            this.abierto = true;
        },

        editar(c) {
            this.modo = 'editar';
            this.id = c.id;
            this.persona = c.tipo_persona;
            this.f = {
                tipo_documento: c.tipo_documento,
                documento: c.documento ?? '',
                nombres: c.nombres ?? '',
                apellidos: c.apellidos ?? '',
                razon_social: c.razon_social ?? '',
                nombre_comercial: c.nombre_comercial ?? '',
                representante_legal: c.representante_legal ?? '',
                direccion: c.direccion ?? '',
                telefono: c.telefono ?? '',
                email: c.email ?? '',
            };
            this.activo = c.activo;
            this.abierto = true;
        },

        eliminar(c) {
            this.id = c.id;
            this.f.razon_social = c.nombre;
            this.ventas = c.ventas;
            this.borrando = true;
        }
    }" @keydown.escape.window="abierto = false; borrando = false" class="space-y-6">

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Registrar al cliente es <b>opcional</b>: la venta al paso se cobra sin pedir ningún dato y el recibo
                sale a nombre genérico.
                @facturacion
                    Solo la <b>factura</b> obliga a identificarlo, y para eso tiene que ser persona jurídica con NIT y
                    dirección fiscal.
                @endfacturacion
            </p>

            <form method="GET" action="{{ route('clientes.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:items-start">
                <div class="sm:col-span-2">
                    <x-form.campo label="Buscar" for="buscar">
                        <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']"
                            placeholder="Nombre, razón social o documento" />
                    </x-form.campo>
                </div>

                <x-form.campo label="Tipo" for="persona">
                    <x-form.select id="persona" name="persona" :value="$filtros['persona']" placeholder="Todos"
                        :opciones="['NATURAL' => 'Persona natural', 'JURIDICA' => 'Persona jurídica']" />
                </x-form.campo>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('clientes.index')">Limpiar</x-ui.button>
                    <x-ui.button size="sm" class="ml-auto" @click="nuevo()">Nuevo cliente</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th clave="nombre" defecto>Cliente</x-tabla.th>
                            <x-tabla.th clave="documento">Documento</x-tabla.th>
                            <x-tabla.th clave="tipo">Tipo</x-tabla.th>
                            <x-tabla.th clave="contacto">Contacto</x-tabla.th>
                            <x-tabla.th clave="compras" inicial="desc">Compras</x-tabla.th>
                            <x-tabla.th derecha>Acciones</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($clientes as $cliente)
                            @php
                                $datos = [
                                    'id' => $cliente->id,
                                    'nombre' => $cliente->nombre,
                                    'tipo_persona' => $cliente->tipo_persona,
                                    'tipo_documento' => $cliente->tipo_documento,
                                    'documento' => $cliente->documento,
                                    'nombres' => $cliente->nombres,
                                    'apellidos' => $cliente->apellidos,
                                    'razon_social' => $cliente->razon_social,
                                    'nombre_comercial' => $cliente->nombre_comercial,
                                    'representante_legal' => $cliente->representante_legal,
                                    'direccion' => $cliente->direccion,
                                    'telefono' => $cliente->telefono,
                                    'email' => $cliente->email,
                                    'activo' => (bool) $cliente->activo,
                                    'ventas' => $cliente->ventas_count,
                                ];
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.inicial :nombre="$cliente->nombre" size="sm" />
                                        <div>
                                            <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                                {{ $cliente->nombre }}
                                            </span>
                                            @if ($cliente->nombre_comercial)
                                                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                                    {{ $cliente->nombre_comercial }}
                                                </span>
                                            @endif
                                            @unless ($cliente->activo)
                                                <x-ui.estado estado="CESADO" texto="Inactivo" />
                                            @endunless
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap font-mono text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $cliente->documento ? $cliente->tipo_documento.' '.$cliente->documento : '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    @facturacion
                                        <x-ui.estado :estado="$cliente->llevaFactura() ? 'PLAZO_FIJO' : 'PRACTICAS'"
                                            :texto="$cliente->esJuridica() ? 'Jurídica — factura' : ($cliente->llevaFactura() ? 'Natural con NIT — factura' : 'Natural — recibo')" />
                                    @else
                                        <x-ui.estado :estado="$cliente->esJuridica() ? 'PLAZO_FIJO' : 'PRACTICAS'"
                                            :texto="$cliente->esJuridica() ? 'Empresa' : 'Persona'" />
                                    @endfacturacion
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ collect([$cliente->telefono, $cliente->email])->filter()->implode(' · ') ?: '—' }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $cliente->ventas_count ?: 'sin compras' }}
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
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay clientes con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$clientes" />
        </div>

        {{-- Alta y edición --}}
        <div x-show="abierto" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cliente"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="abierto = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="abierto"
                class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-cliente" class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90"
                    x-text="modo === 'crear' ? 'Nuevo cliente' : 'Editar cliente'"></h2>

                <form method="POST" :action="modo === 'crear' ? '{{ route('clientes.store') }}' : `/clientes/${id}`"
                    class="space-y-5">
                    @csrf
                    {{-- `cliente_id` no lo usa el controlador: sirve para reabrir el
                         modal en modo edición cuando la validación rebota. --}}
                    <input type="hidden" name="cliente_id" :value="modo === 'editar' ? id : ''" />
                    <input type="hidden" name="tipo_persona" :value="persona" />
                    <template x-if="modo === 'editar'">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="cambiarPersona('NATURAL')"
                            :class="!juridica
                                ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                                : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                            class="rounded-xl border-2 px-4 py-3 text-left transition">
                            <span class="block text-sm font-medium">Persona natural</span>
                            @facturacion<span class="block text-theme-xs opacity-75">Recibe recibo</span>@endfacturacion
                        </button>
                        <button type="button" @click="cambiarPersona('JURIDICA')"
                            :class="juridica
                                ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                                : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-400'"
                            class="rounded-xl border-2 px-4 py-3 text-left transition">
                            <span class="block text-sm font-medium">Persona jurídica</span>
                            @facturacion<span class="block text-theme-xs opacity-75">Recibe factura</span>@endfacturacion
                        </button>
                    </div>

                    {{-- Persona natural --}}
                    <template x-if="!juridica">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <x-form.campo label="Nombres" for="cliente-nombres" name="nombres" required>
                                <x-form.input id="cliente-nombres" name="nombres" x-model="f.nombres"
                                    placeholder="Carlos" />
                            </x-form.campo>

                            <x-form.campo label="Apellidos" for="cliente-apellidos" name="apellidos" required>
                                <x-form.input id="cliente-apellidos" name="apellidos" x-model="f.apellidos"
                                    placeholder="Mendoza Ríos" />
                            </x-form.campo>

                            <x-form.campo label="Tipo de documento" for="cliente-tipodoc" name="tipo_documento" required>
                                <x-form.select id="cliente-tipodoc" name="tipo_documento" x-model="f.tipo_documento"
                                    :opciones="['CI' => 'CI (cédula de identidad)', 'NIT' => App\Support\Config::facturacionVisible() ? 'NIT (unipersonal: recibe factura)' : 'NIT', 'CE' => 'Carné de extranjería', 'PAS' => 'Pasaporte', 'SIN' => 'Sin documento']" />
                            </x-form.campo>

                            <x-form.campo label="Documento" for="cliente-doc-nat" name="documento">
                                <x-form.input id="cliente-doc-nat" name="documento" x-model="f.documento"
                                    placeholder="45678901" />
                            </x-form.campo>
                        </div>
                    </template>

                    {{-- Persona jurídica --}}
                    <template x-if="juridica">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-form.campo label="Razón social" for="cliente-razon" name="razon_social" required>
                                    <x-form.input id="cliente-razon" name="razon_social" x-model="f.razon_social"
                                        placeholder="Distribuidora Oriente S.R.L." />
                                </x-form.campo>
                            </div>

                            <x-form.campo label="NIT" for="cliente-nit" name="documento" required
                                :help="App\Support\Config::facturacionVisible() ? 'Sin NIT no se puede emitir factura.' : null">
                                <x-form.input id="cliente-nit" name="documento" x-model="f.documento" inputmode="numeric"
                                    placeholder="1023456027" />
                                <input type="hidden" name="tipo_documento" value="NIT" />
                            </x-form.campo>

                            <x-form.campo label="Nombre comercial" for="cliente-comercial" name="nombre_comercial">
                                <x-form.input id="cliente-comercial" name="nombre_comercial"
                                    x-model="f.nombre_comercial" placeholder="Oriente" />
                            </x-form.campo>

                            <div class="sm:col-span-2">
                                <x-form.campo label="Representante legal" for="cliente-representante"
                                    name="representante_legal">
                                    <x-form.input id="cliente-representante" name="representante_legal"
                                        x-model="f.representante_legal" placeholder="Julia Ortega Salas" />
                                </x-form.campo>
                            </div>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-form.campo label="Dirección" for="cliente-direccion" name="direccion"
                                ::required="juridica"
                                :help="App\Support\Config::facturacionVisible() ? 'Obligatoria para la factura: es la dirección fiscal.' : null">
                                <x-form.input id="cliente-direccion" name="direccion" x-model="f.direccion"
                                    placeholder="Av. Cañoto 450, Santa Cruz" />
                            </x-form.campo>
                        </div>

                        <x-form.campo label="Teléfono" for="cliente-telefono" name="telefono">
                            <x-form.input id="cliente-telefono" name="telefono" x-model="f.telefono"
                                placeholder="70012345" />
                        </x-form.campo>

                        <x-form.campo label="Correo" for="cliente-email" name="email">
                            <x-form.input id="cliente-email" name="email" type="email" x-model="f.email"
                                placeholder="cliente@correo.com" />
                        </x-form.campo>
                    </div>

                    <x-form.check name="activo" model="activo" label="Cliente disponible para asignar a una venta" />

                    <div class="flex justify-end gap-3">
                        <x-ui.button type="button" variant="outline" size="sm" @click="abierto = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" size="sm">Guardar</x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Baja --}}
        <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-cliente"
            class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
            <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

            <div x-trap.inert.noscroll="borrando"
                class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                <h2 id="titulo-modal-eliminar-cliente" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Eliminar cliente</h2>
                <p class="mb-2 text-theme-sm text-gray-500 dark:text-gray-400">
                    ¿Eliminar a <b x-text="f.razon_social"></b>?
                </p>
                <p x-show="ventas > 0" class="mb-6 text-theme-sm text-warning-700 dark:text-orange-400">
                    Tiene <span x-text="ventas"></span> compra(s) registrada(s), así que se desactivará en lugar de
                    eliminarse: los comprobantes emitidos lo referencian.
                </p>

                <form method="POST" :action="`/clientes/${id}`" class="flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                    <x-ui.button type="submit" variant="danger" size="sm">Eliminar</x-ui.button>
                </form>
            </div>
        </div>
    </div>
@endsection
