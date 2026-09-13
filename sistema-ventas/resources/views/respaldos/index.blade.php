@extends('layouts.app')

@php
    use Illuminate\Support\Number;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
                <h2 class="mb-2 text-base font-medium text-gray-800 dark:text-white/90">Qué guarda un respaldo</h2>
                <p class="max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                    La base entera —productos, clientes, ventas, compras, caja y usuarios— y, en un archivo aparte, las
                    fotos de los productos. Se guardan los de los últimos {{ $dias }} días; el más reciente se conserva
                    siempre.
                </p>
                <p class="mt-3 max-w-3xl text-theme-sm text-gray-500 dark:text-gray-400">
                    Donde el sistema corre con Docker se hace uno solo todas las noches. En un hosting compartido nadie
                    los programa: hazlo desde aquí y descárgalo a otra computadora, porque un respaldo que vive en el
                    mismo servidor se pierde junto con él.
                </p>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="mb-2 text-base font-medium text-gray-800 dark:text-white/90">Último respaldo</h2>
                @if ($ultimo)
                    <p class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $ultimo['fecha']->diffForHumans() }}</p>
                    <p class="mt-1 font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $ultimo['fecha']->format('d/m/Y H:i') }} · {{ Number::fileSize($ultimo['bytes']) }}
                    </p>
                @else
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Ninguno todavía.</p>
                @endif

                <form method="POST" action="{{ route('respaldos.store') }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit" size="sm">Hacer un respaldo ahora</x-ui.button>
                </form>
            </div>
        </div>

        @if (! $ultimo)
            <x-ui.alert variant="warning" title="Todavía no hay ningún respaldo"
                message="Si hoy se rompe el servidor, no hay de dónde recuperar las ventas. Haz el primero ahora." />
        @elseif ($viejo)
            <x-ui.alert variant="warning" title="El último respaldo tiene más de una semana"
                :message="'Es del '.$ultimo['fecha']->format('d/m/Y').'. Todo lo que se vendió después no está guardado en ningún lado.'" />
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Contenido</th>
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Archivo</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tamaño</th>
                            <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($respaldos as $respaldo)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                    {{ $respaldo['fecha']->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ $respaldo['tipo'] === 'base' ? 'Base de datos' : 'Fotos de productos' }}
                                </td>
                                <td class="px-5 py-4 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $respaldo['nombre'] }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-right font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                    {{ Number::fileSize($respaldo['bytes']) }}
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('respaldos.descargar', $respaldo['nombre']) }}"
                                        class="text-theme-sm font-medium text-brand-500 hover:underline">Descargar</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay respaldos guardados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="mb-2 text-base font-medium text-gray-800 dark:text-white/90">Cómo se restaura</h2>
            <ul class="max-w-3xl list-disc space-y-1.5 pl-5 text-theme-sm text-gray-500 dark:text-gray-400">
                <li>En un hosting: en phpMyAdmin, elige la base, <b>Importar</b> y sube el archivo <span class="font-mono">ventas_db_….sql.gz</span> tal cual.</li>
                <li>Con Docker: <span class="font-mono">scripts/restore-db.sh ventas_db_….sql.gz</span>, que repone también las fotos si el archivo de fotos está al lado.</li>
                <li>El archivo reemplaza el contenido de la base elegida. No crea ni borra bases.</li>
            </ul>
        </div>
    </div>
@endsection
