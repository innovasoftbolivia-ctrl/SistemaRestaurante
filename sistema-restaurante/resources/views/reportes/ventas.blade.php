@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();

    $grafico = [
        'tipo' => 'area',
        'moneda' => $moneda,
        'categorias' => array_column($porDia, 'etiqueta'),
        'series' => [['name' => 'Vendido', 'data' => array_column($porDia, 'monto')]],
        'alto' => 320,
    ];

    // Una sola serie por gráfico, en el color de la marca: la tabla de al lado
    // dice lo mismo en números, para quien no distingue el color o imprime.
    $graficoHoras = [
        'tipo' => 'bar',
        'moneda' => $moneda,
        'categorias' => array_column($porHora, 'etiqueta'),
        'series' => [['name' => 'Vendido', 'data' => array_column($porHora, 'monto')]],
        'alto' => 280,
    ];

    $graficoSemana = [
        'tipo' => 'bar',
        'moneda' => $moneda,
        'categorias' => array_map(fn ($d) => mb_substr($d['dia'], 0, 3), $porDiaSemana),
        'series' => [['name' => 'Promedio por jornada', 'data' => array_column($porDiaSemana, 'promedio')]],
        'alto' => 280,
    ];

    $horaFuerte = collect($porHora)->sortByDesc('monto')->first();
    $diaFuerte = collect($porDiaSemana)->where('jornadas', '>', 0)->sortByDesc('promedio')->first();
    $diaFlojo = collect($porDiaSemana)->where('jornadas', '>', 0)->sortBy('promedio')->first();
    $faltantes = $cuadres->where('diferencia', '<', 0);
    $sobrantes = $cuadres->where('diferencia', '>', 0);

    $graficoMetodos = [
        'tipo' => 'donut',
        'moneda' => $moneda,
        'etiquetas' => $porMetodo->pluck('metodo_pago')->all(),
        'series' => $porMetodo->pluck('monto')->map(fn ($m) => (float) $m)->all(),
        'leyenda' => true,
        'alto' => 300,
    ];
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.ventas')" :excel="route('reportes.ventas.excel')" :pdf="route('reportes.ventas.pdf')" :desde="$desde" :hasta="$hasta" />

        {{-- La frase responde lo que de verdad se pregunta un dueño de
             negocio ("¿cómo me fue?"), antes que la grilla de cifras sueltas.
             Dice cuánto se vendió, no cuánto se ganó: el plato se hace en la
             casa y no hay costo de compra con el que calcular la ganancia. --}}
        <div class="rounded-2xl border border-brand-200 bg-brand-50 p-6 dark:border-brand-800 dark:bg-brand-500/10">
            @php
                // `diffInDays` entre un `startOfDay` y un `endOfDay` no cae en un
                // entero exacto (23:59:59.999999 de por medio) y deja un
                // "30.999999999988 días" en pantalla si no se redondea a día.
                $diasPeriodo = (int) $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
            @endphp
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Jornadas del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}
                ({{ $diasPeriodo }} {{ $diasPeriodo === 1 ? 'jornada' : 'jornadas' }})
            </p>
            <p class="mt-1 text-title-md font-bold text-gray-800 dark:text-white/90">
                Vendiste <span class="text-brand-600 dark:text-brand-400">{{ Config::importe($resumen['vendido']) }}</span>
                @if (Config::tasaImpuesto() > 0)
                    <span class="text-theme-sm font-medium text-gray-500 dark:text-gray-400" data-rotulo-vendido>con impuesto</span>
                @endif
            </p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400" data-nota-jornada>
                {{ \App\Http\Controllers\ReporteController::notaJornada() }}
            </p>
            @if ($variacion !== null)
                <p class="mt-2 flex items-center gap-1.5 text-theme-sm {{ $variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        @if ($variacion >= 0)
                            <path d="M12 19V5M12 5l-6 6M12 5l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @else
                            <path d="M12 5v14M12 19l-6-6M12 19l6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @endif
                    </svg>
                    {{ number_format(abs($variacion), 1) }}% {{ $variacion >= 0 ? 'más' : 'menos' }} que el período anterior
                </p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Operaciones</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($resumen['operaciones']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">ventas cobradas</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ticket promedio</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ Config::importe($resumen['ticket']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">por venta</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]" data-descuentos>
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Descuentos</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ Config::importe($resumen['descuentos']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    dejados de cobrar en {{ $resumen['con_descuento'] }} {{ $resumen['con_descuento'] === 1 ? 'venta' : 'ventas' }}
                </p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas anuladas</p>
                <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($resumen['anuladas']) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    @if ($resumen['anuladas'] > 0)
                        por {{ Config::importe($resumen['monto_anulado']) }}; no cuentan en lo vendido
                    @else
                        no afectan estas cifras
                    @endif
                </p>
            </div>
        </div>

        {{-- La cifra que se compara con los arqueos: lo vendido mezcla tarjeta,
             QR y transferencia, que nunca pasan por un cajón. --}}
        <p class="text-theme-sm text-gray-600 dark:text-gray-400" data-efectivo-en-cajas>
            Efectivo que pasó por las cajas:
            <b class="text-gray-800 dark:text-white/90">{{ Config::importe($resumen['efectivo']) }}</b>
            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                — ventas cobradas en efectivo, más ingresos y menos egresos de caja. Cuadra con los arqueos
                de los turnos que abren y cierran dentro del período, sin su monto inicial (un turno que cierra
                pasada la hora de corte cae en el cuadre de la jornada siguiente).
            </span>
        </p>

        @if (Config::tasaImpuesto() > 0 && Config::facturacionVisible())
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                Impuesto del período: {{ Config::importe($resumen['impuesto']) }} (incluido en lo vendido).
            </p>
        @endif

        {{-- Evolución diaria --}}
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas por jornada</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Las jornadas sin ventas se dibujan en cero: unir dos jornadas lejanas con una recta aparentaría
                    ventas que no existieron.
                </p>
            </div>
            <div class="px-3 pb-3">
                <div data-apexchart="{{ json_encode($grafico) }}"></div>
            </div>
        </div>

        {{-- Cuándo se vende: para decidir turnos, compras y promociones. --}}
        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2" data-cuando-se-vende>
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por hora del día</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($horaFuerte)
                            La hora más fuerte es de <b class="text-gray-800 dark:text-white/90">{{ $horaFuerte['tramo'] }}</b>,
                            con {{ Config::importe($horaFuerte['monto']) }} en el período. Ahí hace falta más gente.
                        @else
                            Sin ventas en el período.
                        @endif
                    </p>
                </div>
                @if ($porHora)
                    <div class="px-3 pb-3">
                        <div data-apexchart="{{ json_encode($graficoHoras) }}"></div>
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por día de la semana</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($diaFuerte && $diaFuerte['promedio'] > 0)
                            En promedio, el mejor día es el <b class="text-gray-800 dark:text-white/90">{{ mb_strtolower($diaFuerte['dia']) }}</b>
                            ({{ Config::importe($diaFuerte['promedio']) }} por jornada)
                            @if ($diaFlojo && $diaFlojo['dia'] !== $diaFuerte['dia'])
                                y el más flojo, el {{ mb_strtolower($diaFlojo['dia']) }} ({{ Config::importe($diaFlojo['promedio']) }}).
                            @endif
                        @else
                            Sin ventas en el período.
                        @endif
                    </p>
                </div>
                @if ($diaFuerte && $diaFuerte['promedio'] > 0)
                    <div class="px-3 pb-3">
                        <div data-apexchart="{{ json_encode($graficoSemana) }}"></div>
                    </div>
                    <div class="border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-6 py-2 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Día</th>
                                    <th class="px-6 py-2 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Jornadas</th>
                                    <th class="px-6 py-2 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Promedio</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($porDiaSemana as $d)
                                    <tr>
                                        <td class="px-6 py-2 text-theme-sm text-gray-800 dark:text-white/90">{{ $d['dia'] }}</td>
                                        <td class="px-6 py-2 text-right text-theme-sm text-gray-500 dark:text-gray-400">{{ $d['jornadas'] }}</td>
                                        <td class="px-6 py-2 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ Config::importe($d['promedio']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Método de pago --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por método de pago</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Solo el efectivo queda en el cajón; el resto no cuenta para el arqueo.
                    </p>
                </div>

                @if ($porMetodo->isEmpty())
                    <p class="px-6 pb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        Sin cobros en el período.
                    </p>
                @else
                    <div class="px-3">
                        <div data-apexchart="{{ json_encode($graficoMetodos) }}"></div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($porMetodo as $fila)
                                    <tr>
                                        <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->metodo_pago }}
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $fila->ventas }} venta(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ Config::importe($fila->monto) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Por cajero --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por cajero</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Quién vendió cuánto en el período.
                    </p>
                </div>

                <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cajero</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Descontó</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($porCajero as $fila)
                                <tr>
                                    <td class="px-6 py-3">
                                        <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->empleado }}
                                        </span>
                                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $fila->usuario }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $fila->ventas }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ Config::importe($fila->ticket) }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        @if ((float) $fila->descontado > 0)
                                            {{ Config::importe($fila->descontado) }}
                                            <span class="block text-theme-xs">en {{ $fila->con_descuento }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ Config::importe($fila->monto) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                        Sin ventas en el período.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($porTipo->count() > 0)
                    <div class="border-t border-gray-100 px-6 py-4 dark:border-gray-800" data-por-tipo>
                        <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Comer aquí o para llevar</p>
                        @php $totalTipos = $porTipo->sum('monto'); @endphp
                        @foreach ($porTipo as $t)
                            <div class="flex items-baseline justify-between py-1 text-theme-sm">
                                <span class="text-gray-800 dark:text-white/90">{{ $t->tipo }}
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">· {{ $t->ventas }} ventas, ticket {{ Config::importe($t->ticket) }}</span>
                                </span>
                                <span class="font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($t->monto) }}
                                    <span class="text-theme-xs font-normal text-gray-500 dark:text-gray-400">({{ $totalTipos > 0 ? number_format($t->monto / $totalTipos * 100, 0) : 0 }}%)</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Control: donde se escapa la plata sin que nadie lo note. --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2" data-control>
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-cuadre-caja>
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Cuadre de caja</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($cuadres->isEmpty())
                            No se cerró ningún turno en el período.
                        @elseif ($faltantes->isEmpty() && $sobrantes->isEmpty())
                            Los {{ $cuadres->count() }} turnos cerrados cuadraron al centavo.
                        @else
                            {{ $cuadres->count() }} turnos cerrados:
                            @if ($faltantes->isNotEmpty())
                                <b class="text-error-600 dark:text-error-400">{{ $faltantes->count() }} con faltante ({{ Config::importe(abs($faltantes->sum('diferencia'))) }})</b>
                            @endif
                            @if ($faltantes->isNotEmpty() && $sobrantes->isNotEmpty()) y @endif
                            @if ($sobrantes->isNotEmpty())
                                {{ $sobrantes->count() }} con sobrante ({{ Config::importe($sobrantes->sum('diferencia')) }})
                            @endif
                        @endif
                        @if ($cuadres->whereNotNull('retirado')->isNotEmpty())
                            <span class="mt-1 block">Retirado de las cajas en el período: <b class="text-gray-800 dark:text-white/90">{{ Config::importe($cuadres->sum('retirado')) }}</b>.</span>
                        @endif
                    </p>
                </div>
                @if ($cuadres->isNotEmpty())
                    <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Turno</th>
                                    <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Esperado</th>
                                    <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Contado</th>
                                    <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Diferencia</th>
                                    <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400" title="Lo contado menos lo que quedó en el cajón para el turno siguiente">Retirado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($cuadres as $c)
                                    <tr>
                                        <td class="px-6 py-3">
                                            <a href="{{ route('caja.show', $c->id) }}" class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                                {{ \Illuminate\Support\Carbon::parse($c->fecha_cierre)->format('d/m H:i') }} · {{ $c->caja }}
                                            </a>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $c->usuario }}@if ($c->cerro && $c->cerro !== $c->usuario) · cerró {{ $c->cerro }}@endif @if ($c->observacion_cierre) · {{ \Illuminate\Support\Str::limit($c->observacion_cierre, 40) }}@endif</span>
                                        </td>
                                        <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">{{ Config::importe($c->monto_esperado) }}</td>
                                        <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">{{ Config::importe($c->monto_declarado) }}</td>
                                        <td class="px-6 py-3 text-right text-theme-sm font-medium {{ (float) $c->diferencia < 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                                            @if ((float) $c->diferencia == 0)
                                                Cuadra
                                            @else
                                                {{ (float) $c->diferencia < 0 ? 'Falta' : 'Sobra' }} {{ Config::importe(abs((float) $c->diferencia)) }}
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $c->retirado === null ? '—' : Config::importe($c->retirado) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-anuladas>
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas anuladas</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($anuladas->isEmpty())
                            No se anuló ninguna venta en el período.
                        @else
                            Quién cobró, quién anuló y por qué. Es lo primero que se revisa cuando la caja no cuadra.
                        @endif
                    </p>
                </div>
                @if ($anuladas->isNotEmpty())
                    <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Venta</th>
                                    <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Motivo</th>
                                    <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($anuladas as $a)
                                    <tr>
                                        <td class="px-6 py-3">
                                            <a href="{{ route('ventas.show', $a->id) }}" class="block whitespace-nowrap text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                                {{ $a->numero_completo ?? '#'.$a->id }}
                                            </a>
                                            <span class="whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ \Illuminate\Support\Carbon::parse($a->fecha)->format('d/m H:i') }} · cobró {{ $a->cobro }}, anuló {{ $a->anulo }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $a->motivo_anulacion }}</td>
                                        <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ Config::importe($a->total) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Detalle diario --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle por jornada</h2>
            </div>

            <div class="tabla-pegada max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Jornada</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket promedio</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ \App\Http\Controllers\ReporteController::rotuloVendidoConImpuesto() }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_reverse(array_filter($porDia, fn ($d) => $d['ventas'] > 0)) as $dia)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                    <a href="{{ route('ventas.index', ['desde' => $dia['dia'], 'hasta' => $dia['dia']]) }}"
                                        class="hover:text-brand-500">
                                        {{ \Illuminate\Support\Carbon::parse($dia['dia'])->format('d/m/Y') }}
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $dia['ventas'] }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($dia['ticket']) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($dia['monto']) }}
                                </td>
                            </tr>
                        @endforeach

                        @if (collect($porDia)->every(fn ($d) => $d['ventas'] === 0))
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hubo ventas en el período.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
