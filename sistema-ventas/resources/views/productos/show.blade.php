@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div x-data="{ borrando: false }" @keydown.escape.window="borrando = false" class="space-y-6">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-4">
                    <x-ui.foto-producto :producto="$producto" size="lg" />
                    <div>
                    <h2 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $producto->nombre }}</h2>
                    <p class="font-mono text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $producto->codigo }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.estado estado="INDEFINIDO" :texto="$producto->categoria?->nombre" />
                        <x-ui.estado :estado="$producto->activo ? 'ACTIVO' : 'CESADO'"
                            :texto="$producto->activo ? 'En el menú' : 'Fuera del menú'" />
                    </div>
                    @if ($producto->descripcion)
                        <p class="mt-3 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">
                            {{ $producto->descripcion }}
                        </p>
                    @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @puede('productos.gestionar')
                        <x-ui.button size="sm" variant="outline" :href="route('productos.edit', $producto)">Editar</x-ui.button>
                    @endpuede
                </div>
            </div>
        </div>

        {{-- Cifras --}}
        <div class="grid grid-cols-1 gap-4">
            @php
                $cifras = [
                    // Sin impuesto el precio de estante y el de venta son el mismo
                    // numero: no tiene sentido anunciarlo como si fueran dos cosas.
                    [Config::tasaImpuesto() > 0 && ! Config::preciosIncluyenImpuesto() ? 'Precio de estante' : 'Precio de venta',
                        Config::importe($producto->precio_estante), 'text-gray-800 dark:text-white/90',
                        Config::tasaImpuesto() > 0
                            ? ($producto->afecto_impuesto ? 'incluye impuesto' : 'exonerado')
                            : 'lo que paga el cliente'],
                ];
            @endphp

            @foreach ($cifras as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Precios --}}
            <div class="lg:col-span-2">
                @php
                    $filasDePrecio = Config::tasaImpuesto() > 0 && Config::preciosIncluyenImpuesto()
                        ? [
                            'Precio de venta' => Config::importe($producto->precio_venta).($producto->afecto_impuesto ? ' (IVA incluido)' : ' (exonerado)'),
                            'Precio sin IVA' => Config::importe($producto->precio_base),
                        ]
                        : (Config::tasaImpuesto() > 0
                        ? [
                            'Precio de venta base' => Config::importe($producto->precio_venta).' (sin impuesto)',
                            'Precio de estante' => Config::importe($producto->precio_estante),
                        ]
                        : [
                            'Precio de venta' => Config::importe($producto->precio_venta),
                        ]);
                @endphp

                <x-common.component-card title="Precios">
                    <dl class="space-y-4">
                        @foreach ($filasDePrecio as $etiqueta => $valor)
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $etiqueta }}
                                </dt>
                                <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $valor }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-common.component-card>
            </div>

            <div class="space-y-6">
                @puede('registros.eliminar')
                    <x-common.component-card title="Quitar del menú">
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Si ya se vendió alguna vez, se retira del menú en lugar de eliminarse.
                        </p>
                        <x-ui.button variant="outline" size="sm" class="w-full" @click="borrando = true">
                            Quitar del menú
                        </x-ui.button>
                    </x-common.component-card>
                @endpuede
            </div>
        </div>

        {{-- Baja --}}
        @puede('registros.eliminar')
            <div x-show="borrando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-eliminar-producto"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="borrando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="borrando"
                    class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-eliminar-producto" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Quitar del menú</h2>
                    <p class="mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        ¿Quitar <b>{{ $producto->nombre }}</b> del menú? Si aparece en alguna venta, solo se retira
                        del menú, para no romper el histórico.
                    </p>

                    <form method="POST" action="{{ route('productos.destroy', $producto) }}" class="flex justify-end gap-3">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="button" variant="outline" size="sm" @click="borrando = false">Cancelar</x-ui.button>
                        <x-ui.button type="submit" variant="danger" size="sm">Quitar</x-ui.button>
                    </form>
                </div>
            </div>
        @endpuede
    </div>
@endsection
