@extends('layouts.app')

@section('content')
    @php $esEdicion = $empleado->exists; @endphp

    <form method="POST" x-data="{ estado: @js(old('estado', $empleado->estado ?? 'ACTIVO')) }"
        action="{{ $esEdicion ? route('empleados.update', $empleado) : route('empleados.store') }}"
        class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="space-y-6 lg:col-span-2">
            <x-common.component-card title="Datos personales"
                desc="Quién es la persona. El documento no se puede repetir entre empleados.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Nombres" for="nombres" name="nombres" required>
                        <x-form.input id="nombres" name="nombres" :value="$empleado->nombres" placeholder="Rocío"
                            required />
                    </x-form.campo>

                    <x-form.campo label="Apellidos" for="apellidos" name="apellidos" required>
                        <x-form.input id="apellidos" name="apellidos" :value="$empleado->apellidos"
                            placeholder="Huamán Peña" required />
                    </x-form.campo>

                    <x-form.campo label="Tipo de documento" for="tipo_documento" name="tipo_documento" required>
                        <x-form.select id="tipo_documento" name="tipo_documento" :value="$empleado->tipo_documento"
                            :opciones="$tiposDocumento" />
                    </x-form.campo>

                    <x-form.campo label="Número de documento" for="documento" name="documento" required>
                        <x-form.input id="documento" name="documento" :value="$empleado->documento"
                            placeholder="10000005" required />
                    </x-form.campo>

                    <x-form.campo label="Fecha de nacimiento" for="fecha_nacimiento" name="fecha_nacimiento">
                        <x-form.input id="fecha_nacimiento" name="fecha_nacimiento" type="date"
                            :value="$empleado->fecha_nacimiento?->format('Y-m-d')" />
                    </x-form.campo>

                    <x-form.campo label="Teléfono" for="telefono" name="telefono">
                        <x-form.input id="telefono" name="telefono" :value="$empleado->telefono"
                            placeholder="70012345" />
                    </x-form.campo>

                    <x-form.campo label="Correo" for="email" name="email">
                        <x-form.input id="email" name="email" type="email" :value="$empleado->email"
                            placeholder="persona@tienda.com" />
                    </x-form.campo>

                    <x-form.campo label="Dirección" for="direccion" name="direccion">
                        <x-form.input id="direccion" name="direccion" :value="$empleado->direccion"
                            placeholder="Av. Siempre Viva 123" />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Vínculo laboral"
                desc="El cargo es la función en el negocio; el acceso al sistema se define aparte, en la cuenta de usuario.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Cargo" for="cargo_id" name="cargo_id" required>
                        <x-form.select id="cargo_id" name="cargo_id" :value="$empleado->cargo_id"
                            placeholder="Selecciona un cargo" :opciones="$cargos" required />
                    </x-form.campo>

                    <x-form.campo label="Tipo de contrato" for="tipo_contrato" name="tipo_contrato" required>
                        <x-form.select id="tipo_contrato" name="tipo_contrato" :value="$empleado->tipo_contrato"
                            :opciones="$tiposContrato" />
                    </x-form.campo>

                    <x-form.campo label="Fecha de ingreso" for="fecha_ingreso" name="fecha_ingreso" required>
                        <x-form.input id="fecha_ingreso" name="fecha_ingreso" type="date"
                            :value="$empleado->fecha_ingreso?->format('Y-m-d') ?? now()->format('Y-m-d')" required />
                    </x-form.campo>

                    <x-form.campo label="Estado" for="estado" name="estado" required
                        help="Al pasar a Cesado o Suspendido, la cuenta del sistema queda sin acceso.">
                        <x-form.select id="estado" name="estado" :value="$empleado->estado" :opciones="$estados"
                            x-model="estado" />
                    </x-form.campo>

                    <template x-if="estado === 'CESADO'">
                        <div class="grid grid-cols-1 gap-5 sm:col-span-2 sm:grid-cols-2">
                            <x-form.campo label="Fecha de cese" for="fecha_cese" name="fecha_cese">
                                <x-form.input id="fecha_cese" name="fecha_cese" type="date"
                                    :value="$empleado->fecha_cese?->format('Y-m-d')" />
                            </x-form.campo>

                            <x-form.campo label="Motivo del cese" for="motivo_cese" name="motivo_cese">
                                <x-form.input id="motivo_cese" name="motivo_cese" :value="$empleado->motivo_cese"
                                    placeholder="Renuncia voluntaria" />
                            </x-form.campo>
                        </div>
                    </template>
                </div>
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Guardar">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                    @if ($esEdicion)
                        Estás editando la ficha de <b>{{ $empleado->nombre_completo }}</b>.
                    @else
                        El empleado queda registrado sin cuenta de acceso. Si necesita entrar al sistema, créale una
                        cuenta desde <b>Usuarios</b>.
                    @endif
                </p>

                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit">
                        {{ $esEdicion ? 'Guardar cambios' : 'Registrar empleado' }}
                    </x-ui.button>
                    <x-ui.button variant="outline" :href="route('empleados.index')">Cancelar</x-ui.button>
                </div>
            </x-common.component-card>

            @if ($esEdicion && $empleado->usuario)
                <x-common.component-card title="Cuenta del sistema">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $empleado->usuario->usuario }}</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                rol {{ $empleado->usuario->rol?->nombre }}
                            </p>
                        </div>
                        <x-ui.estado :estado="$empleado->usuario->activo ? 'ACTIVO' : 'CESADO'"
                            :texto="$empleado->usuario->activo ? 'Habilitado' : 'Deshabilitado'" />
                    </div>

                    @puede('usuarios.gestionar')
                        <x-ui.button variant="outline" size="sm"
                            :href="route('usuarios.edit', $empleado->usuario)">Administrar cuenta</x-ui.button>
                    @endpuede
                </x-common.component-card>
            @endif
        </div>
    </form>
@endsection
