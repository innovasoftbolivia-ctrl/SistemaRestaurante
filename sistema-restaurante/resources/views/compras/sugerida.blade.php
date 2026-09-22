@extends('layouts.app')

@php
    use App\Services\CompraSugerida;
    use App\Support\Config;

    $th = 'px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400';
@endphp

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-sm text-gray-600 dark:text-gray-400">
                Lo que está en su stock mínimo o por debajo, en cajas cerradas: lo que falta para llegar al mínimo
                más lo que se vende en una semana (el promedio de los últimos {{ CompraSugerida::DIAS_HISTORIA }} días).
                Cada proveedor se abre como una compra ya llena: revisa las cantidades y los costos con la factura y
                guárdala. Nada se registra hasta que la guardes.
            </p>
        </div>

        @forelse ($grupos as $grupo)
            @php $proveedor = $grupo['proveedor']; @endphp
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
                data-sugerida-proveedor="{{ $proveedor?->id ?? 0 }}">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div>
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">
                            {{ $proveedor?->razon_social ?? 'Sin proveedor anterior' }}
                        </h2>
                        <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                            @if ($proveedor)
                                {{ $grupo['lineas']->count() }} {{ $grupo['lineas']->count() === 1 ? 'producto' : 'productos' }}
                                · unos {{ Config::importe($grupo['total']) }} con el último costo
                                @if ($proveedor->telefono)
                                    · tel. {{ $proveedor->telefono }}
                                @endif
                            @else
                                Nunca se compraron: elige a quién pedírselos al armar la compra.
                            @endif
                        </p>
                    </div>
                    <x-ui.button size="sm" :href="route('compras.create', ['sugerida' => $proveedor?->id ?? 0])">
                        Armar esta compra
                    </x-ui.button>
                </div>

                <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th class="{{ $th }} text-left">Producto</th>
                                <th class="{{ $th }} text-right">Hay</th>
                                <th class="{{ $th }} text-right">Mínimo</th>
                                <th class="{{ $th }} text-right">Se vende por semana</th>
                                <th class="{{ $th }} text-right">Pedir</th>
                                <th class="{{ $th }} text-right">Costo estimado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($grupo['lineas'] as $linea)
                                @php $p = $linea['producto']; @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('inventario.kardex', $p) }}" class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">{{ $p->nombre }}</a>
                                        <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $p->codigo }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right text-theme-sm tabular-nums {{ $linea['stock'] <= 0 ? 'text-error-600 dark:text-error-400' : 'text-warning-700 dark:text-orange-400' }}">
                                        {{ Config::cantidad($linea['stock']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-theme-sm tabular-nums text-gray-500 dark:text-gray-400">{{ Config::cantidad($linea['minimo']) }}</td>
                                    <td class="px-5 py-3 text-right text-theme-sm tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $linea['por_semana'] > 0 ? Config::cantidad($linea['por_semana']) : 'sin ventas' }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90" data-pedir="{{ $p->id }}">
                                        {{ $p->enEmpaques($linea['cantidad']) }}
                                        @if ($linea['empaques'] > 0)
                                            <span class="block text-theme-xs font-normal text-gray-500 dark:text-gray-400">{{ Config::cantidad($linea['cantidad']) }} u.</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right text-theme-sm tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $linea['costo'] !== null ? Config::importe($linea['importe']) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center dark:border-gray-800 dark:bg-white/[0.03]" data-sugerida-vacia>
                <p class="text-base font-medium text-gray-800 dark:text-white/90">No hace falta comprar nada</p>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Todo lo que lleva inventario está sobre su stock mínimo.</p>
                <div class="mt-4">
                    <x-ui.button size="sm" variant="outline" :href="route('inventario.index')">Ver el inventario</x-ui.button>
                </div>
            </div>
        @endforelse
    </div>
@endsection
