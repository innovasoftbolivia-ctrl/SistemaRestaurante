@extends('layouts.app')

@php
    use App\Http\Controllers\BitacoraController as B;
    use Illuminate\Support\Str;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-4 max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                Todo lo sensible que pasa en el sistema, con quién lo hizo, cuándo y desde qué equipo: ingresos,
                cambios de precio, anulaciones, altas y bajas. Es solo de lectura; lo registrado no se edita ni se borra.
            </p>

            <form method="GET" action="{{ route('bitacora.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start">
                <x-form.campo label="Usuario" for="usuario">
                    <x-form.select id="usuario" name="usuario" :value="$filtros['usuario']" placeholder="Todos"
                        :opciones="$usuarios" />
                </x-form.campo>

                <x-form.campo label="Acción" for="accion">
                    <x-form.select id="accion" name="accion" :value="$filtros['accion']" placeholder="Todas"
                        :opciones="$acciones" />
                </x-form.campo>

                <x-form.campo label="Desde" for="desde" name="desde">
                    <x-form.input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                </x-form.campo>

                <x-form.campo label="Hasta" for="hasta" name="hasta">
                    <x-form.input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                </x-form.campo>

                <x-form.campo label="Buscar" for="buscar" help="Un número, un nombre o un valor que cambió.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="1102" />
                </x-form.campo>

                <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('bitacora.index')">Limpiar</x-ui.button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cuándo</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Quién</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Qué hizo</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Sobre qué</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Detalle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($registros as $registro)
                            @php
                                $sobre = B::entidad($registro->entidad, $registro->entidad_id);
                                $enlace = B::enlace($registro->entidad, $registro->entidad_id, $registro->detalle);
                                $filas = B::detalle($registro->detalle);
                                $quien = $registro->usuario;
                            @endphp
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                    {{ $registro->fecha?->format('d/m/Y') }}<br>
                                    <span class="text-gray-500 dark:text-gray-400">{{ $registro->fecha?->format('H:i:s') }}</span>
                                </td>
                                <td class="px-5 py-4 text-theme-sm">
                                    @if ($quien)
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $quien->usuario }}</span>
                                        @if ($quien->empleado?->nombre_completo)
                                            <br><span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $quien->empleado->nombre_completo }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">—</span>
                                    @endif
                                    @if ($registro->ip)
                                        <br><span class="font-mono text-theme-xs text-gray-400">{{ $registro->ip }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ B::accion($registro->accion) }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-theme-sm">
                                    @if ($enlace)
                                        <a href="{{ $enlace }}" class="text-brand-500 hover:underline dark:text-brand-400">{{ $sobre }}</a>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">{{ $sobre ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-theme-xs text-gray-600 dark:text-gray-400">
                                    @if ($filas)
                                        <dl class="grid max-w-md grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                            @foreach ($filas as [$clave, $texto])
                                                <dt class="text-gray-400">{{ $clave }}</dt>
                                                <dd class="break-words font-mono text-gray-700 dark:text-gray-300" title="{{ $texto }}">
                                                    {{ Str::limit($texto, 120) }}
                                                </dd>
                                            @endforeach
                                        </dl>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay registros con esos criterios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$registros" />
        </div>
    </div>
@endsection
