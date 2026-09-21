@props([
    'accion',
    'desde',
    'hasta',
    // Rutas de descarga. Si no se pasan, el botón no aparece.
    'excel' => null,
    'pdf' => null,
])

@php
    // Atajos habituales del mostrador. El «mes pasado» va completo, de día 1 a fin de mes.
    $mesPasado = now()->subMonthNoOverflow();

    $atajos = [
        'Hoy' => [now(), now()],
        'Últimos 7 días' => [now()->subDays(6), now()],
        'Últimos 30 días' => [now()->subDays(29), now()],
        'Este mes' => [now()->startOfMonth(), now()],
        'Mes pasado' => [$mesPasado->copy()->startOfMonth(), $mesPasado->copy()->endOfMonth()],
    ];

    $actual = [$desde->toDateString(), $hasta->toDateString()];

    // Días de calendario del rango, ambos extremos incluidos. Se comparan a
    // medianoche: `hasta` llega al final del día y un diff en bruto daría
    // 30,999… en vez de 31.
    $dias = (int) $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
    <form method="GET" action="{{ $accion }}" class="grid grid-cols-1 gap-4 md:grid-cols-4 md:items-end">
        <x-form.campo label="Desde" for="desde">
            <x-form.input id="desde" name="desde" type="date" :value="$desde->toDateString()" />
        </x-form.campo>

        <x-form.campo label="Hasta" for="hasta">
            <x-form.input id="hasta" name="hasta" type="date" :value="$hasta->toDateString()" />
        </x-form.campo>

        <div class="md:col-span-2">
            <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-400">Períodos rápidos</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($atajos as $etiqueta => [$d, $h])
                    @php $activo = $actual === [$d->toDateString(), $h->toDateString()]; @endphp
                    <a href="{{ $accion }}?desde={{ $d->toDateString() }}&hasta={{ $h->toDateString() }}"
                        class="rounded-lg px-3 py-2 text-theme-xs font-medium transition {{ $activo
                            ? 'bg-brand-500 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10' }}">
                        {{ $etiqueta }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 md:col-span-4">
            <x-ui.button type="submit" size="sm">Aplicar</x-ui.button>

            @php
                // Enlaces y no botones del formulario: descargan el rango que ya
                // está aplicado, sin reenviarlo.
                $consulta = '?desde='.$desde->toDateString().'&hasta='.$hasta->toDateString();
                $iconoDescarga = '<svg aria-hidden=\'true\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\'><path d=\'M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2\' stroke=\'currentColor\' stroke-width=\'1.8\' stroke-linecap=\'round\' stroke-linejoin=\'round\'/></svg>';
            @endphp

            @if ($excel)
                <x-ui.button size="sm" variant="outline" :href="$excel.$consulta" :start-icon="$iconoDescarga">
                    Excel
                </x-ui.button>
            @endif

            @if ($pdf)
                <x-ui.button size="sm" variant="outline" :href="$pdf.$consulta" :start-icon="$iconoDescarga">
                    PDF
                </x-ui.button>
            @endif

            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                Mostrando del <b>{{ $desde->format('d/m/Y') }}</b> al <b>{{ $hasta->format('d/m/Y') }}</b>
                ({{ $dias }} {{ $dias === 1 ? 'día' : 'días' }}).
            </p>
        </div>
    </form>
</div>
