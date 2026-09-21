@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        <x-ui.inicial :nombre="$empleado->nombre_completo" size="xl" />
                        <div>
                            <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ $empleado->nombre_completo }}
                            </h2>
                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $empleado->cargo?->nombre }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-ui.estado :estado="$empleado->estado" />
                                <x-ui.estado :estado="$empleado->tipo_contrato" />
                            </div>
                        </div>
                    </div>

                    <x-ui.button variant="outline" size="sm" :href="route('empleados.edit', $empleado)">
                        Editar ficha
                    </x-ui.button>
                </div>
            </div>

            <x-common.component-card title="Datos personales">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @foreach ([
                        'Documento' => $empleado->tipo_documento.' '.$empleado->documento,
                        'Fecha de nacimiento' => $empleado->fecha_nacimiento?->format('d/m/Y'),
                        'Teléfono' => $empleado->telefono,
                        'Correo' => $empleado->email,
                        'Dirección' => $empleado->direccion,
                    ] as $etiqueta => $valor)
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $etiqueta }}
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $valor ?: '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-common.component-card>

            <x-common.component-card title="Vínculo laboral">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @php
                        $laborales = [
                            'Cargo' => $empleado->cargo?->nombre,
                            'Fecha de ingreso' => $empleado->fecha_ingreso?->format('d/m/Y'),
                            'Registrado el' => $empleado->creado_en?->format('d/m/Y H:i'),
                        ];

                        if ($empleado->fecha_cese) {
                            $laborales['Fecha de cese'] = $empleado->fecha_cese->format('d/m/Y');
                            $laborales['Motivo del cese'] = $empleado->motivo_cese;
                        }
                    @endphp

                    @foreach ($laborales as $etiqueta => $valor)
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $etiqueta }}
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $valor ?: '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Cuenta del sistema">
                @if ($empleado->usuario)
                    <dl class="space-y-4">
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Usuario
                            </dt>
                            <dd class="font-medium text-gray-800 dark:text-white/90">{{ $empleado->usuario->usuario }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Rol</dt>
                            <dd class="font-medium text-gray-800 dark:text-white/90">{{ $empleado->usuario->rol?->nombre }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Acceso</dt>
                            <dd>
                                <x-ui.estado :estado="$empleado->usuario->activo ? 'ACTIVO' : 'CESADO'"
                                    :texto="$empleado->usuario->activo ? 'Habilitado' : 'Deshabilitado'" />
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Último acceso
                            </dt>
                            <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $empleado->usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca ingresó' }}
                            </dd>
                        </div>
                    </dl>

                    @puede('usuarios.gestionar')
                        <x-ui.button variant="outline" size="sm" class="w-full"
                            :href="route('usuarios.edit', $empleado->usuario)">
                            Administrar cuenta
                        </x-ui.button>
                    @endpuede
                @else
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Trabaja en el negocio pero no tiene cuenta para entrar al sistema.
                    </p>

                    @puede('usuarios.gestionar')
                        @if ($empleado->estado === 'ACTIVO')
                            <x-ui.button size="sm" class="w-full" :href="route('usuarios.create')">
                                Crear cuenta
                            </x-ui.button>
                        @endif
                    @endpuede
                @endif
            </x-common.component-card>

            <x-common.component-card title="Volver">
                <x-ui.button variant="outline" size="sm" class="w-full" :href="route('empleados.index')">
                    Lista de empleados
                </x-ui.button>
            </x-common.component-card>
        </div>
    </div>
@endsection
