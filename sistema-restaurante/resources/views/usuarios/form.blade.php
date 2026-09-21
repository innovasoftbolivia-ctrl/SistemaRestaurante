@extends('layouts.app')

@section('content')
    @php
        $esEdicion = $usuario->exists;
        $opcionesRoles = $roles->pluck('nombre', 'id');
    @endphp

    <form method="POST" action="{{ $esEdicion ? route('usuarios.update', $usuario) : route('usuarios.store') }}"
        class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="space-y-6 lg:col-span-2">
            <x-common.component-card title="Cuenta"
                desc="El usuario y el rol con el que la persona entra al sistema.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Empleado" for="empleado_id" name="empleado_id" required
                        :help="$esEdicion
                            ? 'El empleado de una cuenta no se cambia: si es otra persona, corresponde otra cuenta.'
                            : 'Solo aparecen empleados activos que todavía no tienen cuenta.'">
                        @if ($esEdicion)
                            <p
                                class="flex h-11 items-center rounded-lg border border-gray-200 bg-gray-50 px-4 text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
                                {{ $usuario->empleado?->nombre_completo }}
                            </p>
                        @else
                            <x-form.select id="empleado_id" name="empleado_id" :value="$usuario->empleado_id"
                                placeholder="Selecciona un empleado" :opciones="$empleados" required />
                        @endif
                    </x-form.campo>

                    <x-form.campo label="Nombre de usuario" for="usuario" name="usuario" required
                        help="Minúsculas, números, punto, guion y guion bajo.">
                        <x-form.input id="usuario" name="usuario" :value="$usuario->usuario" placeholder="cajero2"
                            required />
                    </x-form.campo>

                    <div class="sm:col-span-2">
                        <x-form.campo label="Rol de acceso" for="rol_id" name="rol_id" required>
                            <x-form.select id="rol_id" name="rol_id" :value="$usuario->rol_id"
                                placeholder="Selecciona un rol" :opciones="$opcionesRoles" required />
                        </x-form.campo>

                        <div class="mt-3 space-y-1">
                            @foreach ($roles as $rol)
                                @if ($rol->descripcion)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        <b>{{ $rol->nombre }}:</b> {{ $rol->descripcion }}
                                    </p>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                @if (! $esEdicion && $empleados->isEmpty())
                    <x-ui.alert variant="warning" title="No hay empleados disponibles"
                        message="Todos los empleados activos ya tienen cuenta. Registra primero al empleado en Personal → Empleados." />
                @endif

                <x-form.check name="activo" :checked="$usuario->activo ?? true"
                    label="La cuenta puede ingresar al sistema" />
            </x-common.component-card>

            <x-common.component-card :title="$esEdicion ? 'Restablecer contraseña' : 'Contraseña inicial'"
                :desc="$esEdicion ? 'Déjalo en blanco para conservar la contraseña actual.' : 'Mínimo 8 caracteres.'">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Contraseña" for="password" name="password" :required="! $esEdicion">
                        <x-form.input id="password" name="password" type="password" autocomplete="new-password"
                            :required="! $esEdicion" />
                    </x-form.campo>

                    <x-form.campo label="Repetir contraseña" for="password_confirmation">
                        <x-form.input id="password_confirmation" name="password_confirmation" type="password"
                            autocomplete="new-password" :required="! $esEdicion" />
                    </x-form.campo>
                </div>
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Guardar">
                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit">
                        {{ $esEdicion ? 'Guardar cambios' : 'Crear cuenta' }}
                    </x-ui.button>
                    <x-ui.button variant="outline" :href="route('usuarios.index')">Cancelar</x-ui.button>
                </div>
            </x-common.component-card>

            @if ($esEdicion)
                <x-common.component-card title="Estado actual">
                    <dl class="space-y-4">
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Acceso
                            </dt>
                            <dd>
                                <x-ui.estado :estado="$usuario->activo ? 'ACTIVO' : 'CESADO'"
                                    :texto="$usuario->activo ? 'Habilitado' : 'Deshabilitado'" />
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Último acceso
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca ingresó' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Intentos fallidos
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $usuario->intentos_fallidos }}
                            </dd>
                        </div>
                    </dl>
                </x-common.component-card>
            @endif
        </div>
    </form>
@endsection
