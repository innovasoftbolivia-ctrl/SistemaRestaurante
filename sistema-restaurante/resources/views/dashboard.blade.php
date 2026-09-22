@extends('layouts.app')

@php
    use App\Http\Controllers\CocinaController;
    use App\Support\Config;

    $moneda = Config::moneda();

    // Las tres vistas del gráfico de ventas. El gris es la referencia (la
    // semana anterior, un día normal); el color de la marca, lo de ahora.
    $vistas = [
        '7' => ['boton' => '7 días', 'nota' => 'Cada día, junto al mismo día de la semana anterior.'],
        'hoy' => ['boton' => 'Hoy', 'nota' => 'Por hora, frente al promedio de los últimos '.($hoy ? (str_ends_with($hoy['dia'], 's') ? $hoy['dia'] : $hoy['dia'].'s') : 'días').' a la misma hora.'],
        '30' => ['boton' => '30 días', 'nota' => 'Lo vendido cada día del último mes.'],
    ];
    // Los 30 días son una tendencia: en área, con una fecha de cada tanto en
    // el eje (en barras, treinta fechas se amontonan).
    $configGrafico = fn (string $clave) => [
        'tipo' => $clave === '30' ? 'area' : 'bar',
        'moneda' => $moneda,
        'categorias' => $graficos[$clave]['categorias'],
        'series' => $graficos[$clave]['series'],
        'colores' => ['#0a5cff', '#98a2b3'],
        'leyenda' => count($graficos[$clave]['series']) > 1,
        'alto' => 260,
    ];

    $totalPagos = $pagos ? (float) $pagos->sum('monto') : 0;

    $clasePedido = fn (string $clase) => match ($clase) {
        'exito' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
        'aviso' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
        'apagado' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
        default => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
    };

    $tarjeta = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $rotulo = 'text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400';
@endphp

@section('content')
    {{-- Quien gestiona deja la portada abierta: se recarga sola cada minuto,
         solo si la pestaña está a la vista. --}}
    <div class="space-y-6"
        @if ($gestion) x-data x-init="setInterval(() => { if (document.visibilityState === 'visible') location.reload() }, {{ $segundos * 1000 }})" data-se-actualiza @endif>

        {{-- Saludo: el nombre y la fecha. Las cajas abiertas ya están en la barra
             de arriba, y el mostrador y los reportes, en el menú. --}}
        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                @php $hora = (int) now()->format('G'); @endphp
                {{ $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches') }},
                {{ $usuario->empleado?->nombres ?? $usuario->usuario }}
            </h2>
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                {{ ucfirst(now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')) }}
            </p>
        </div>

        {{-- Mi turno: del cajero. El administrador ve su caja en la barra de
             arriba y lo vendido en el panel. --}}
        @if (! $gestion && ($sesion || $mias))
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="{{ $tarjeta }} p-5 lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="mb-1 {{ $rotulo }}">
                                Mi turno
                            </p>
                            @if ($sesion)
                                <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                    {{ $sesion->caja?->nombre }}
                                </p>
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Abierto a las {{ $sesion->fecha_apertura?->format('H:i') }} ·
                                    {{ $sesion->ventas_count }} venta(s) ·
                                    inicial {{ Config::importe($sesion->monto_inicial) }}
                                </p>
                            @else
                                <p class="text-lg font-semibold text-gray-800 dark:text-white/90">Sin caja abierta</p>
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Cada venta se imputa a un turno. Abre el tuyo para empezar a cobrar.
                                </p>
                            @endif
                        </div>

                        @if ($sesion && $veArqueo)
                            <div class="text-right">
                                <p class="{{ $rotulo }}">
                                    Efectivo esperado
                                </p>
                                <p class="text-title-sm font-semibold text-brand-500 dark:text-brand-400">
                                    {{ Config::importe($sesion->efectivoEsperado()) }}
                                </p>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @puede('ventas.registrar')
                            <x-ui.button size="xs" :href="route('pos.index')">Ir al mostrador</x-ui.button>
                        @endpuede
                        @if ($sesion)
                            <x-ui.button size="xs" variant="outline" :href="route('caja.show', $sesion)">
                                Ver turno y cerrar
                            </x-ui.button>
                        @else
                            @puede('caja.abrir')
                                <x-ui.button size="xs" :href="route('caja.index')">Abrir caja</x-ui.button>
                            @endpuede
                        @endif
                    </div>
                </div>

                @if ($mias)
                    <div class="{{ $tarjeta }} p-5">
                        <p class="mb-1 {{ $rotulo }}">
                            Lo que llevo vendido hoy
                        </p>
                        <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ Config::importe($mias['monto']) }}
                        </p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $mias['operaciones'] }} operación(es) a mi nombre
                        </p>
                    </div>
                @endif
            </div>
        @endif

        @if ($gestion)
            {{-- ------------------------------------------------ los cuatro números --}}
            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                @php
                    $variacion = $hoy['variacion'];
                    $sinVentas = $hoy['hoy']['operaciones'] === 0;
                @endphp

                <div class="{{ $tarjeta }} p-5" data-kpi="vendido">
                    <p class="mb-1 {{ $rotulo }}">Vendido hoy</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ Config::importe($hoy['hoy']['monto']) }}</p>
                    {{-- Sin ventas todavía hoy, la comparación daría −100% en rojo
                         cada mañana: una alarma que no es. Se espera a la primera. --}}
                    @if ($sinVentas)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400" data-aun-sin-ventas>aún sin ventas hoy</p>
                    @elseif ($variacion === null)
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">el {{ $hoy['dia'] }} pasado no hubo ventas</p>
                    @else
                        <p class="mt-1 text-theme-xs font-medium {{ $variacion >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400' }}" data-variacion>
                            {{ $variacion > 0 ? '+' : '' }}{{ number_format($variacion, 1) }}% que el {{ $hoy['dia'] }} pasado
                        </p>
                    @endif
                </div>

                <div class="{{ $tarjeta }} p-5" data-kpi="ventas">
                    <p class="mb-1 {{ $rotulo }}">Ventas</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($hoy['hoy']['operaciones']) }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">ticket promedio {{ Config::importe($hoy['hoy']['ticket']) }}</p>
                </div>

                @php
                    $enCocina = $cocina['hacer'] + $cocina['cocinando'];
                    $enlace = 'block transition hover:border-brand-300 dark:hover:border-brand-800';
                @endphp
                @php $veCocina = $usuario->tienePermiso('cocina.ver'); @endphp
                <{{ $veCocina ? 'a' : 'div' }} @if ($veCocina) href="{{ route('cocina.index') }}" @endif
                    class="{{ $tarjeta }} {{ $veCocina ? $enlace : '' }} p-5" data-kpi="cocina">
                    <p class="mb-1 {{ $rotulo }}">En cocina</p>
                    <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $enCocina }} {{ $enCocina === 1 ? 'pedido' : 'pedidos' }}
                    </p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400" data-cocina-desglose>
                        {{ $cocina['hacer'] }} por hacer · {{ $cocina['cocinando'] }} cocinando · {{ $cocina['entregar'] }} listos
                    </p>
                    @if ($cocina['espera'] !== null && $cocina['espera'] >= CocinaController::MINUTOS_AVISO)
                        <p class="mt-0.5 text-theme-xs font-medium {{ $cocina['espera'] >= CocinaController::MINUTOS_TARDE ? 'text-error-600 dark:text-error-400' : 'text-warning-700 dark:text-orange-400' }}">
                            el más viejo espera hace {{ $cocina['espera'] }} min
                        </p>
                    @endif
                </{{ $veCocina ? 'a' : 'div' }}>

                @if ($stock !== null)
                    <a href="{{ route('inventario.index') }}" class="{{ $tarjeta }} {{ $enlace }} p-5" data-kpi="stock">
                        <p class="mb-1 {{ $rotulo }}">Por comprar</p>
                        <p class="text-title-sm font-semibold {{ $stock['total'] > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                            {{ $stock['total'] }} {{ $stock['total'] === 1 ? 'producto' : 'productos' }}
                        </p>
                        @if ($stock['total'] > 0)
                            <p class="mt-1 truncate text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $stock['productos']->take(2)->map(fn ($p) => $p->nombre.': '.((float) $p->stock_actual <= 0 ? 'sin stock' : 'quedan '.Config::cantidad($p->stock_actual)))->implode(' · ') }}
                            </p>
                        @else
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">todo sobre su mínimo</p>
                        @endif
                    </a>
                @else
                    <div class="{{ $tarjeta }} p-5" data-kpi="listos">
                        <p class="mb-1 {{ $rotulo }}">Para entregar</p>
                        <p class="text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ $cocina['entregar'] }}</p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">pedidos listos en la cocina</p>
                    </div>
                @endif
            </div>

            {{-- -------------------------------------- por hora y formas de pago --}}
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                {{-- Un gráfico, tres vistas. Los tres se dibujan al cargar y los
                     botones solo muestran uno. El que estaba oculto se dibujó sin
                     ancho: al mostrarlo se avisa un cambio de tamaño de la
                     ventana, que es lo que ApexCharts escucha para redibujarse. --}}
                <div class="{{ $tarjeta }} xl:col-span-2" data-grafico-ventas x-data="{ vista: '7' }">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-6 pt-5">
                        <div>
                            <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas</h2>
                            @foreach ($vistas as $clave => $vista)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-show="vista === '{{ $clave }}'" @if ($clave !== '7') x-cloak @endif>
                                    {{ $vista['nota'] }}
                                </p>
                            @endforeach
                        </div>
                        @if ($graficos)
                            <div class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-white/5" role="group" aria-label="Período del gráfico">
                                @foreach ($vistas as $clave => $vista)
                                    <button type="button" @click="vista = '{{ $clave }}'; $nextTick(() => window.dispatchEvent(new Event('resize')))" :aria-pressed="vista === '{{ $clave }}'"
                                        data-vista="{{ $clave }}"
                                        class="rounded-md px-3 py-1.5 text-theme-sm font-medium transition"
                                        :class="vista === '{{ $clave }}' ? 'bg-white text-gray-900 shadow-theme-xs dark:bg-gray-800 dark:text-white' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white'">
                                        {{ $vista['boton'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if ($graficos)
                        @foreach ($vistas as $clave => $vista)
                            <div class="px-3 pb-3" x-show="vista === '{{ $clave }}'" @if ($clave !== '7') x-cloak @endif>
                                <div data-apexchart="{{ json_encode($configGrafico($clave)) }}"></div>
                            </div>
                        @endforeach
                    @else
                        <p class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">Todavía no hay ventas para mostrar.</p>
                    @endif
                </div>

                <div class="{{ $tarjeta }} p-6" data-formas-de-pago>
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Formas de pago</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Lo cobrado hoy.</p>
                    <div class="mt-5 space-y-4">
                        @forelse ($pagos as $pago)
                            @php $peso = $totalPagos > 0 ? (float) $pago->monto / $totalPagos * 100 : 0; @endphp
                            <div>
                                <div class="flex items-baseline justify-between gap-2 text-theme-sm">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $pago->metodo }}</span>
                                    <span class="font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Config::importe($pago->monto) }}</span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                                        <div class="h-2 rounded-full bg-brand-500" style="width: {{ max(2, round($peso)) }}%"></div>
                                    </div>
                                    <span class="w-9 text-right text-theme-xs tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($peso, 0) }}%</span>
                                </div>
                            </div>
                        @empty
                            <p class="py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">Todavía no se cobró nada hoy.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- --------------------------------- pedidos y lo más vendido hoy --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="{{ $tarjeta }}" data-ultimos-pedidos>
                    <div class="flex items-center justify-between gap-3 px-6 pt-5 pb-3">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Últimos pedidos</h2>
                        <span class="inline-flex items-center gap-1.5 text-theme-xs text-success-700 dark:text-success-500">
                            <span class="h-1.5 w-1.5 rounded-full bg-success-500" aria-hidden="true"></span> cada minuto
                        </span>
                    </div>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($pedidos as ['pedido' => $pedido, 'estado' => $estado, 'clase' => $clase])
                            <li class="flex items-center justify-between gap-3 px-6 py-3">
                                <span class="min-w-0 text-theme-sm">
                                    <span class="font-semibold text-gray-800 dark:text-white/90">#{{ $pedido->numero_dia }}</span>
                                    <span class="text-gray-600 dark:text-gray-400">{{ $pedido->destino }}{{ $pedido->quien ? ' · '.$pedido->quien : '' }}</span>
                                    <span class="block text-theme-xs text-gray-400 dark:text-gray-500">{{ $pedido->fecha_apertura?->format('H:i') }}</span>
                                </span>
                                <span class="flex-none rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $clasePedido($clase) }}">{{ $estado }}</span>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">Todavía no hay pedidos hoy.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="{{ $tarjeta }}" data-top-hoy>
                    <div class="flex items-center justify-between gap-3 px-6 pt-5 pb-3">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Lo más vendido hoy</h2>
                        <a href="{{ route('reportes.productos') }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Menú</a>
                    </div>
                    <ol class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($top as $i => $fila)
                            <li class="flex items-center justify-between gap-3 px-6 py-3 text-theme-sm">
                                <span class="min-w-0 truncate text-gray-800 dark:text-white/90">
                                    <span class="mr-1 text-gray-400 dark:text-gray-500">{{ $i + 1 }}.</span>{{ $fila->nombre }}
                                </span>
                                <span class="flex-none text-right">
                                    <span class="block font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Config::cantidad($fila->unidades) }} u.</span>
                                    <span class="block text-theme-xs tabular-nums text-gray-500 dark:text-gray-400">{{ Config::importe($fila->monto) }}</span>
                                </span>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">Todavía no se vendió nada hoy.</li>
                        @endforelse
                    </ol>
                </div>
            </div>
        @endif
    </div>
@endsection
