@extends('layouts.app')

@php
    use App\Models\PedidoDetalle;
@endphp

{{-- Antes de pintar: si la tablet quedó en pantalla completa, arranca así, sin
     que se vea un instante la barra lateral. --}}
@push('antes-de-pintar')
    <script>
        try {
            if (localStorage.getItem('cocina.pantallaCompleta') === '1') {
                document.documentElement.classList.add('modo-cocina');
            }
        } catch (e) {}
    </script>
@endpush

@section('content')
    {{-- La pantalla de la cocina se mira de lejos y con las manos ocupadas, y la
         usa también quien lleva los platos. Es un tablero: por hacer → cocinando
         → para entregar, y el pedido pasa de una columna a la otra con UN botón.
         Cada pedido se reconoce por su NÚMERO, en grande —el que se canta y el
         que el cliente tiene en su ticket—; el reloj dice cuánto lleva esperando
         y cambia de color; la nota va destacada. Tocar un plato suelto lo avanza
         solo a él. Se refresca sola preguntando el mismo listado en JSON. --}}
    <div x-data="cocina()" x-init="vigilar(); marcarReloj()" class="space-y-4">

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center gap-2 text-theme-sm">
                {{-- El título de la página ya lo dice; en pantalla completa, que lo esconde, lo dice esto. --}}
                <span class="mr-1 hidden text-base font-semibold text-gray-800 dark:text-white/90 [.modo-cocina_&]:inline">Cocina</span>
                {{-- Cada contador baja a su columna: en una tableta de pie, «para
                     entregar» queda debajo de las otras dos y así se llega de un
                     toque. --}}
                @foreach ($columnas as $clave => $columna)
                    <a href="#columna-{{ $clave }}" data-contador="{{ $clave }}" class="rounded-full px-3 py-1 font-medium transition hover:brightness-95 {{ match ($clave) {
                        'hacer' => 'bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300',
                        'cocinando' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
                        default => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
                    } }}">
                        {{ $columna['tandas']->count() }} {{ mb_strtolower($columna['titulo']) }}
                    </a>
                @endforeach
                <span class="flex items-center gap-1.5 text-theme-xs text-gray-400 dark:text-gray-500">
                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span>
                    Se actualiza sola
                    <span x-show="hayNovedad" x-cloak class="font-medium text-brand-500 dark:text-brand-400">· hay cambios</span>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button size="sm" variant="outline" @click="alternarPantalla()" data-pantalla-completa>
                    <span x-text="completa ? 'Salir de pantalla completa' : 'Pantalla completa'">Pantalla completa</span>
                </x-ui.button>
                <x-ui.button size="sm" variant="outline" @click="window.location.reload()">Actualizar</x-ui.button>
            </div>
        </div>

        @if ($soloEntrega)
            <p class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-theme-sm text-brand-700 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-400" data-solo-entrega>
                Ves la cocina para entregar: cuando un pedido pasa a <b>Para entregar</b>, canta su número y
                toca <b>Entregado</b> al dárselo al cliente. La preparación la mueve la cocina.
            </p>
        @endif

        {{-- En una tableta de pie, tres columnas quedan de 244 px y el nombre del
             plato se parte en dos o tres renglones. Ahí van dos columnas de
             trabajo —por hacer y cocinando— y «para entregar» a lo ancho debajo,
             con sus tarjetas de a dos: se ve entero sin apretar nada. Desde
             1024 px (tableta acostada o pantalla de cocina) vuelven las tres. --}}
        <div class="grid grid-cols-1 items-start gap-3 md:grid-cols-2 lg:grid-cols-3 lg:gap-4">
            @foreach ($columnas as $clave => $columna)
                <section data-columna="{{ $clave }}" aria-labelledby="columna-{{ $clave }}"
                    class="min-w-0 rounded-2xl p-3 {{ $clave === 'entregar' ? 'md:col-span-2 lg:col-span-1' : '' }} {{ match ($clave) {
                        'hacer' => 'bg-gray-100 dark:bg-white/[0.03]',
                        'cocinando' => 'bg-warning-50/70 dark:bg-warning-500/[0.06]',
                        default => 'bg-success-50 dark:bg-success-500/10',
                    } }}">
                    {{-- `scroll-mt`: con la cabecera pegada arriba, el salto desde
                         el contador no deja el título tapado. --}}
                    <h2 id="columna-{{ $clave }}"
                        class="scroll-mt-24 flex items-center justify-between px-1 pb-3 text-theme-sm font-semibold uppercase tracking-wide {{ match ($clave) {
                            'hacer' => 'text-gray-600 dark:text-gray-400',
                            'cocinando' => 'text-warning-700 dark:text-orange-400',
                            default => 'text-success-700 dark:text-success-500',
                        } }}">
                        {{ $columna['titulo'] }}
                        <span class="rounded-full bg-white px-2.5 py-0.5 font-mono text-theme-sm dark:bg-gray-900">{{ $columna['tandas']->count() }}</span>
                    </h2>

                    <div class="space-y-3 {{ $clave === 'entregar' ? 'md:grid md:grid-cols-2 md:gap-3 md:space-y-0 lg:block lg:space-y-3' : '' }}">
                        @forelse ($columna['tandas'] as $tanda)
                            @include('cocina._pedido', ['tanda' => $tanda, 'columna' => $clave])
                        @empty
                            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-theme-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                {{ match ($clave) {
                                    'hacer' => 'Nada por empezar. Lo que se cobre en el mostrador aparece aquí solo.',
                                    'cocinando' => 'Nada en el fuego.',
                                    default => 'Nada por entregar.',
                                } }}
                            </p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    @push('scripts')
        <script>
            function cocina() {
                return {
                    hayNovedad: false,
                    completa: document.documentElement.classList.contains('modo-cocina'),
                    /* El reloj de cada pedido cuenta en el navegador, con la
                       hora del servidor como referencia: la tablet puede tener
                       la hora corrida. */
                    desfase: {{ now()->getTimestampMs() }} - Date.now(),
                    ahora: {{ now()->getTimestampMs() }},
                    aviso: {{ $minutosAviso }},
                    tarde: {{ $minutosTarde }},
                    /* La huella de lo que hay en pantalla: id + estado de cada
                       plato. Si cambia, la página se vuelve a cargar. */
                    huella: @js($tandas->flatMap(fn ($t) => $t['lineas']->map(fn ($l) => $l->id.':'.$l->estado_cocina))->values()->all()),

                    marcarReloj() {
                        setInterval(() => { this.ahora = Date.now() + this.desfase; }, 15000);
                    },

                    minutos(desde) {
                        return Math.max(0, Math.floor((this.ahora - desde) / 60000));
                    },

                    espera(desde) {
                        const m = this.minutos(desde);
                        if (m < 1) return 'recién';
                        if (m < 60) return m + ' min';
                        return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
                    },

                    colorEspera(desde) {
                        const m = this.minutos(desde);
                        if (m >= this.tarde) return 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400';
                        if (m >= this.aviso) return 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400';
                        return 'bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300';
                    },

                    /* Sin menú ni cabecera, para que en la tablet entren más
                       pedidos. Se recuerda en esa tablet; la pantalla completa
                       del navegador se pide aparte y el navegador puede negarla. */
                    alternarPantalla() {
                        this.completa = !this.completa;
                        document.documentElement.classList.toggle('modo-cocina', this.completa);
                        try { localStorage.setItem('cocina.pantallaCompleta', this.completa ? '1' : '0'); } catch (e) {}
                        try {
                            if (this.completa && !document.fullscreenElement) {
                                document.documentElement.requestFullscreen?.().catch(() => {});
                            } else if (!this.completa && document.fullscreenElement) {
                                document.exitFullscreen?.().catch(() => {});
                            }
                        } catch (e) {}
                    },

                    /* Sondeo y no WebSocket: el local tiene una pantalla en la
                       cocina, y un servidor de eventos sería una pieza más que
                       mantener en un hosting donde ni los procedimientos de MySQL
                       se pueden dar por seguros.

                       Cuando algo cambió se recarga la página entera en vez de
                       redibujar el tablero en el navegador: así hay una sola
                       versión de esta pantalla —la del servidor— y no dos que
                       puedan acabar diciendo cosas distintas. */
                    vigilar() {
                        setInterval(async () => {
                            try {
                                const r = await fetch('{{ route('cocina.pendientes') }}', {
                                    headers: { 'Accept': 'application/json' },
                                });

                                if (!r.ok) return;

                                const datos = await r.json();
                                const ahora = datos.tandas.flatMap(
                                    t => t.lineas.map(l => l.id + ':' + l.estado));

                                if (ahora.join('|') !== this.huella.join('|')) {
                                    this.hayNovedad = true;
                                    window.location.reload();
                                }
                            } catch (e) {
                                /* Sin red: se reintenta en la vuelta siguiente. */
                            }
                        }, {{ $segundos }} * 1000);
                    },
                };
            }
        </script>
    @endpush
@endsection
