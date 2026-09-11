@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6" x-data="bajasDeTanda()">
        {{-- «Sin fecha» va aquí arriba y no escondido: si quedaron 200 unidades
             sin fechar, «no tengo nada por vencer» no quiere decir que todo esté
             bien, quiere decir que el control todavía no está completo. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $tarjetas = [
                    ['Ya vencidos', number_format($resumen['vencidos']),
                        $resumen['vencidos'] > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90',
                        $resumen['vencidos'] > 0 ? Config::importe($resumen['valor_vencido']).' inmovilizados' : 'nada vencido'],
                    ['Por vencer', number_format($resumen['por_vencer']),
                        $resumen['por_vencer'] > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-800 dark:text-white/90',
                        'en los próximos '.$dias.' días'],
                    ['Sin fecha registrada', Config::cantidad($resumen['sin_fecha']),
                        'text-gray-800 dark:text-white/90', 'unidades que nadie fechó'],
                    ['Productos con control', number_format($resumen['controlados']),
                        'text-gray-800 dark:text-white/90', 'el resto no vence'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        {{-- La ventana de aviso, a un clic --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">Mirar lo que vence dentro de</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($ventanas as $ventana)
                    <a href="{{ route('vencimientos.index', ['dias' => $ventana]) }}"
                        class="rounded-lg px-3 py-2 text-theme-xs font-medium transition {{ $dias === $ventana
                            ? 'bg-brand-500 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10' }}">
                        {{ $ventana }} días
                    </a>
                @endforeach
            </div>
            <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                Lo ya vencido sale siempre, sin importar la ventana elegida.
            </p>
        </div>

        {{-- El listado --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            @foreach (['Producto', 'Vence', 'Faltan', 'Quedan', 'Valor', ''] as $i => $columna)
                                <th class="px-5 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ $i >= 2 ? 'text-right' : 'text-left' }}">
                                    {{ $columna }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($lotes as $lote)
                            @php($vencido = $lote->dias < 0)
                            @php($tanda = [
                                'id' => $lote->id,
                                'producto' => $lote->producto,
                                'lote' => $lote->codigo,
                                'cantidad' => (float) $lote->cantidad_actual,
                                'unidad' => $lote->unidad,
                                'valor' => (float) $lote->valor,
                                'vencio' => \Illuminate\Support\Carbon::parse($lote->fecha_vencimiento)->format('d/m/Y'),
                            ])
                            <tr class="{{ $vencido ? 'bg-error-50/50 dark:bg-error-500/5' : '' }}">
                                <td class="px-5 py-4">
                                    <a href="{{ route('vencimientos.producto', $lote->producto_id) }}"
                                        class="block font-medium text-gray-800 hover:text-brand-500 text-theme-sm dark:text-white/90">
                                        {{ $lote->producto }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $lote->producto_codigo }}@if ($lote->codigo) · lote {{ $lote->codigo }}@endif
                                    </span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ \Illuminate\Support\Carbon::parse($lote->fecha_vencimiento)->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium {{ $vencido ? 'text-error-600 dark:text-error-400' : ($lote->dias <= 7 ? 'text-warning-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400') }}">
                                    @if ($vencido)
                                        vencido hace {{ abs($lote->dias) }} d
                                    @elseif ($lote->dias === 0)
                                        vence hoy
                                    @else
                                        {{ $lote->dias }} d
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ Config::cantidad($lote->cantidad_actual) }} {{ $lote->unidad }}
                                </td>
                                <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($lote->valor) }}
                                </td>
                                {{-- Ver el problema y poder resolverlo desde el
                                     mismo sitio. Cuál de las dos salidas se
                                     ofrece no es cosmético: al proveedor solo se
                                     le puede devolver lo que él trajo, y solo si
                                     esa línea todavía tiene algo sin devolver.
                                     Lo demás —el stock que ya estaba cuando se
                                     encendió el control— sale por un ajuste,
                                     que es lo honesto: no hay factura contra la
                                     que reclamar. --}}
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-2">
                                        {{-- Devolver va primero cuando se puede:
                                             es la salida que recupera plata. --}}
                                        @if ($lote->compra_id && $lote->pendiente_devolucion > 0)
                                            @puede('inventario.ingresar')
                                                <x-ui.button size="sm" variant="outline"
                                                    :href="route('devoluciones-compra.create', ['compra' => $lote->compra_id, 'lote' => $lote->id])">
                                                    Devolver
                                                </x-ui.button>
                                            @endpuede
                                        @endif

                                        {{-- Y la baja solo para lo ya vencido: lo
                                             que caduca la semana que viene todavía
                                             se vende, y ofrecer tirarlo sería
                                             invitar a tirar mercadería buena. --}}
                                        @if ($vencido)
                                            @puede('inventario.ajustar')
                                                {{-- Botón llano y no `x-ui.button`:
                                                     Blade no compila `@js()` dentro
                                                     del atributo de un componente
                                                     —el valor viaja como texto—, y
                                                     el literal `@js($tanda)` acababa
                                                     en el HTML. Mismo patrón que en
                                                     «Cajas del local». --}}
                                                <button type="button" @click="abrir(@js($tanda))"
                                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03] dark:hover:text-gray-300">
                                                    Dar de baja
                                                </button>
                                            @endpuede
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Nada vence en los próximos {{ $dias }} días.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$lotes" />
        </div>

        @puede('inventario.ajustar')
            <div x-show="tanda" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-baja"
                @keydown.escape.window="tanda = null"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="tanda = null" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="tanda"
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <template x-if="tanda">
                        <div>
                            <h2 id="titulo-baja" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                                Dar de baja lo vencido
                            </h2>
                            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                                <b x-text="tanda.producto"></b><span x-show="tanda.lote">, lote <span x-text="tanda.lote"></span></span>,
                                venció el <span x-text="tanda.vencio"></span>.
                            </p>

                            <div class="mb-6 rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                                <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Sale del inventario
                                </p>
                                <p class="text-lg font-semibold text-error-600 dark:text-error-400">
                                    − <span x-text="tanda.cantidad"></span> <span x-text="tanda.unidad"></span>
                                </p>
                                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Support\Config::moneda() }} <span x-text="tanda.valor.toFixed(2)"></span> al costo.
                                    El resto del stock de ese producto no se toca.
                                </p>
                            </div>

                            <form method="POST" :action="`{{ url('vencimientos') }}/${tanda.id}/baja`" class="space-y-5">
                                @csrf

                                <x-form.campo label="Observación" for="baja_observacion" name="observacion"
                                    help="Opcional. El motivo —«baja por vencimiento», la tanda y la fecha— lo escribe el sistema.">
                                    <x-form.input id="baja_observacion" name="observacion" maxlength="120"
                                        placeholder="Se botó / se devolvió al distribuidor en mano…" />
                                </x-form.campo>

                                <div class="flex justify-end gap-3">
                                    <x-ui.button type="button" variant="outline" size="sm"
                                        @click="tanda = null">Cancelar</x-ui.button>
                                    <x-ui.button type="submit" size="sm">Dar de baja</x-ui.button>
                                </div>
                            </form>
                        </div>
                    </template>
                </div>
            </div>
        @endpuede

        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            El mostrador despacha siempre del lote que vence antes, así que lo que aparece aquí es lo que de verdad
            queda de esa tanda. <strong>Devolver</strong> lleva a la factura por la que entró, con la cantidad y el
            motivo ya puestos, y sale solo cuando hay una compra detrás a la que todavía le queda algo por devolver.
            <strong>Dar de baja</strong> saca del inventario lo que ya venció y nadie va a reponer: descuenta esa
            tanda y nada más, y deja su ajuste en el kardex con responsable y motivo. Mientras no se haga una de las
            dos cosas, el sistema sigue creyendo que esas unidades se pueden vender.
        </p>
    </div>
@endsection

@push('scripts')
    <script>
        function bajasDeTanda() {
            return {
                /* La tanda que se está por dar de baja, o null. Guardarla
                   entera —y no solo el id— es lo que deja mostrar en el
                   diálogo cuánto sale y de qué, que es lo único que evita
                   confirmar a ciegas. */
                tanda: null,

                abrir(tanda) {
                    this.tanda = tanda;
                },
            };
        }
    </script>
@endpush
