@extends('layouts.app')

@section('content')
    @php $empleado = $usuario->empleado; @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
                <div class="flex items-center gap-4">
                    <x-ui.inicial :nombre="$usuario->nombre_completo" size="xl" marca />
                    <div>
                        <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ $usuario->nombre_completo }}
                        </h2>
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $usuario->usuario }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <x-ui.estado estado="INDEFINIDO" :texto="'Rol: '.$usuario->rol?->nombre" />
                            <x-ui.estado estado="PRACTICAS" :texto="'Cargo: '.$empleado?->cargo?->nombre" />
                        </div>
                    </div>
                </div>
            </div>

            <x-common.component-card title="Mis datos"
                desc="Tus datos personales los mantiene el administrador desde el módulo de Empleados.">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @foreach ([
                        'Documento' => $empleado ? $empleado->tipo_documento.' '.$empleado->documento : null,
                        'Correo' => $empleado?->email,
                        'Teléfono' => $empleado?->telefono,
                        'Fecha de ingreso' => $empleado?->fecha_ingreso?->format('d/m/Y'),
                        'Último acceso' => $usuario->ultimo_acceso?->format('d/m/Y H:i'),
                        'Contraseña actualizada' => $usuario->password_actualizado_en?->format('d/m/Y'),
                    ] as $etiqueta => $valor)
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $etiqueta }}
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $valor ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-common.component-card>

            <x-common.component-card title="Lo que tu rol te permite"
                desc="Estos son los permisos que otorga el rol {{ $usuario->rol?->nombre }}.">
                @forelse ($permisosPorModulo as $modulo => $permisos)
                    <div>
                        <b class="text-theme-sm text-gray-800 dark:text-white/90">{{ $modulo }}</b>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($permisos as $permiso)
                                <li class="flex items-start gap-2 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <svg aria-hidden="true" class="mt-0.5 flex-none text-success-500" width="16" height="16"
                                        viewBox="0 0 24 24" fill="none">
                                        <path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    {{ $permiso->descripcion }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Tu rol no tiene permisos asignados.</p>
                @endforelse
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Cambiar contraseña" desc="Mínimo 8 caracteres.">
                <form method="POST" action="{{ route('perfil.password') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <x-form.campo label="Contraseña actual" for="password_actual" name="password_actual" required>
                        <x-form.input id="password_actual" name="password_actual" type="password"
                            autocomplete="current-password" required />
                    </x-form.campo>

                    <x-form.campo label="Nueva contraseña" for="password" name="password" required>
                        <x-form.input id="password" name="password" type="password" autocomplete="new-password"
                            required />
                    </x-form.campo>

                    <x-form.campo label="Repetir nueva contraseña" for="password_confirmation">
                        <x-form.input id="password_confirmation" name="password_confirmation" type="password"
                            autocomplete="new-password" required />
                    </x-form.campo>

                    <x-ui.button type="submit" class="w-full">Actualizar contraseña</x-ui.button>
                </form>
            </x-common.component-card>
        </div>
    </div>
@endsection
