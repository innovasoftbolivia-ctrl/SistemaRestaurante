@php
    use App\Support\Config;

    $moneda = Config::moneda();
    $puedeAjustar = auth()->user()->tienePermiso('inventario.ajustar');
    // Quien solo mira reportes ve la toma, pero sin casillas para contar.
    $abierta = $toma->estaAbierta();
    $editable = $abierta && $puedeAjustar;
@endphp

@extends('layouts.app')

@section('content')
    <div class="space-y-6" x-data="tomaInventario(@js($resumen))" @keydown.window="atajo($event)">

        {{-- Cabecera: de qué toma se trata y cuánto falta. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $toma->alcance }}</h2>
                        @switch($toma->estado)
                            @case('ABIERTA')
                                <x-ui.badge size="sm" color="warning">En curso</x-ui.badge>
                            @break
                            @case('CERRADA')
                                <x-ui.badge size="sm" color="success">Cerrada</x-ui.badge>
                            @break
                            @default
                                <x-ui.badge size="sm" color="light">Cancelada</x-ui.badge>
                        @endswitch
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Abierta el {{ $toma->fecha_apertura?->format('d/m/Y H:i') }} por {{ $toma->usuarioApertura?->usuario }}
                        @if ($toma->fecha_cierre)
                            · {{ $toma->estado === 'CERRADA' ? 'cerrada' : 'cancelada' }} el {{ $toma->fecha_cierre->format('d/m/Y H:i') }}
                            por {{ $toma->usuarioCierre?->usuario }}
                        @endif
                    </p>
                    @if ($toma->observacion)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $toma->observacion }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="outline" :href="route('tomas.imprimir', $toma)" target="_blank">
                        {{ $abierta ? 'Planilla para contar' : 'Imprimir resultado' }}
                    </x-ui.button>
                    @if ($abierta && $puedeAjustar)
                        <x-ui.button size="sm" variant="outline" type="button" @click="confirmar = 'cancelar'">Cancelar toma</x-ui.button>
                        <x-ui.button size="sm" type="button" @click="confirmar = 'cerrar'">Cerrar y ajustar stock</x-ui.button>
                    @endif
                </div>
            </div>

            {{-- Avance y lo que va saliendo. Se actualiza con cada conteo. --}}
            <div class="mt-6 space-y-3">
                <div class="flex items-baseline justify-between gap-3 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">
                        Contados <b class="text-gray-800 tabular-nums dark:text-white/90" x-text="numero(resumen.contados)"></b>
                        de <span class="tabular-nums" x-text="numero(resumen.total)"></span>
                    </span>
                    <span class="tabular-nums text-gray-500 dark:text-gray-400" x-text="porcentaje + ' %'"></span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]" role="progressbar"
                    aria-label="Avance del conteo" aria-valuemin="0" :aria-valuemax="resumen.total" :aria-valuenow="resumen.contados">
                    <div class="h-full rounded-full bg-brand-500 transition-all" :style="'width: ' + porcentaje + '%'"></div>
                </div>

                <dl class="grid grid-cols-2 gap-3 pt-2 sm:grid-cols-3" aria-live="polite">
                    <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Con diferencia</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-800 dark:text-white/90" x-text="numero(resumen.con_diferencia)"></dd>
                    </div>
                    <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Falta (al costo)</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-error-600 dark:text-error-400" x-text="'{{ $moneda }} ' + dinero(resumen.faltante)"></dd>
                    </div>
                    <div class="col-span-2 rounded-xl bg-gray-50 px-4 py-3 sm:col-span-1 dark:bg-white/[0.03]">
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Sobra (al costo)</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-success-700 dark:text-success-500" x-text="'{{ $moneda }} ' + dinero(resumen.sobrante)"></dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            {{-- Buscar o escanear. Con la pistola: escanea, escribe la cantidad,
                 Enter, y el cursor vuelve aquí para el siguiente. --}}
            <form method="GET" action="{{ route('tomas.show', $toma) }}"
                class="flex flex-col gap-3 border-b border-gray-100 p-5 sm:flex-row sm:items-end dark:border-gray-800">
                <x-form.campo label="Buscar o escanear" for="buscar" class="sm:flex-1">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" x-ref="buscador"
                        autocomplete="off" placeholder="Nombre, código o código de barras"
                        :autofocus="$enfocar === null" />
                </x-form.campo>
                <x-form.campo label="Mostrar" for="estado" class="sm:w-48">
                    <x-form.select id="estado" name="estado" :value="$filtros['estado']" placeholder="Todos"
                        :opciones="\App\Http\Controllers\TomaInventarioController::ESTADOS" onchange="this.form.submit()" />
                </x-form.campo>
                @if ($categorias->isNotEmpty())
                    <x-form.campo label="Categoría" for="categoria" class="sm:w-48">
                        <x-form.select id="categoria" name="categoria" :value="$filtros['categoria']" placeholder="Todas"
                            :opciones="$categorias" onchange="this.form.submit()" />
                    </x-form.campo>
                @endif
                <div class="flex gap-2">
                    <x-ui.button type="submit" size="sm">Buscar</x-ui.button>
                    @if ($filtros['buscar'] !== '' || $filtros['estado'] !== '' || $filtros['categoria'])
                        <x-ui.button size="sm" variant="outline" :href="route('tomas.show', $toma)">Limpiar</x-ui.button>
                    @endif
                </div>
            </form>

            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th derecha>Contado</x-tabla.th>
                            <x-tabla.th derecha>Sistema</x-tabla.th>
                            <x-tabla.th derecha>Diferencia</x-tabla.th>
                            <x-tabla.th derecha class="hidden md:table-cell">Valor</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($lineas as $linea)
                            @php
                                $producto = $linea->producto;
                                $decimal = (bool) $producto->unidadMedida?->permite_decimal;
                            @endphp
                            <tr x-data="lineaToma(@js([
                                    'url' => route('tomas.contar', [$toma, $linea]),
                                    'contado' => $linea->contado === null ? null : (float) $linea->contado,
                                    'sistema' => $linea->stock_sistema === null ? null : (float) $linea->stock_sistema,
                                    'diferencia' => $linea->diferencia === null ? null : (float) $linea->diferencia,
                                    'valor' => $linea->contado === null ? null : $linea->valor_diferencia,
                                    'enfocar' => $enfocar === $linea->id,
                                ]))"
                                :class="diferencia !== null && diferencia !== 0 ? 'bg-warning-25 dark:bg-orange-500/[0.04]' : ''">
                                <td class="px-5 py-3">
                                    <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $producto->nombre }}</span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        @if (! $toma->categoria_id)
                                            {{ $producto->categoria?->nombre }} ·
                                        @endif
                                        {{ $producto->codigo }}@if ($producto->codigo_barras) · {{ $producto->codigo_barras }}@endif
                                        · {{ $producto->unidadMedida?->codigo }}
                                        @if ($producto->tieneEmpaque())
                                            · {{ $producto->etiqueta_empaque }}
                                        @endif
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($editable)
                                        <label for="contado-{{ $linea->id }}" class="sr-only">Contado de {{ $producto->nombre }}</label>
                                        <input id="contado-{{ $linea->id }}" x-ref="casilla" type="number" inputmode="{{ $decimal ? 'decimal' : 'numeric' }}"
                                            min="0" step="{{ $decimal ? '0.001' : '1' }}" x-model="valor"
                                            @change="guardar()" @keydown.enter.prevent="guardar(true)"
                                            :aria-invalid="error ? 'true' : 'false'"
                                            :class="error ? 'border-error-500' : (guardado ? 'border-success-500' : 'border-gray-300 dark:border-gray-700')"
                                            class="h-10 w-24 rounded-lg border bg-transparent px-2 text-right text-sm tabular-nums text-gray-800 focus:ring-2 focus:outline-hidden dark:bg-gray-900 dark:text-white/90" />
                                        <span x-show="guardando" class="block text-theme-xs text-gray-400">Guardando…</span>
                                        <span x-show="error" x-text="error" role="alert" class="block max-w-40 text-theme-xs text-error-600 dark:text-error-400"></span>
                                    @else
                                        <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">
                                            {{ $linea->contado === null ? 'sin contar' : Config::cantidad($linea->contado) }}
                                        </span>
                                    @endif
                                </td>
                                {{-- El stock del sistema aparece recién después de contar:
                                     se cuenta lo que hay en el estante, no se confirma un número. --}}
                                <td class="px-5 py-3 text-right text-theme-sm tabular-nums text-gray-500 dark:text-gray-400"
                                    x-text="sistema === null ? '—' : cantidad(sistema)"></td>
                                <td class="px-5 py-3 text-right text-theme-sm font-medium tabular-nums"
                                    :class="diferencia === null || diferencia === 0 ? 'text-gray-500 dark:text-gray-400' : (diferencia < 0 ? 'text-error-600 dark:text-error-400' : 'text-success-700 dark:text-success-500')"
                                    x-text="diferencia === null ? '—' : (diferencia === 0 ? 'cuadra' : (diferencia > 0 ? '+' : '') + cantidad(diferencia))"></td>
                                <td class="hidden px-5 py-3 text-right text-theme-sm tabular-nums text-gray-500 md:table-cell dark:text-gray-400"
                                    x-text="!diferencia ? '—' : (valorDiferencia < 0 ? '− ' : '+ ') + '{{ $moneda }} ' + dinero(Math.abs(valorDiferencia))"></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Ningún producto de esta toma coincide con la búsqueda.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-common.paginacion :paginador="$lineas" />
        </div>

        @if ($editable)
            <div x-show="confirmar" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-confirmar"
                @keydown.escape.window="confirmar = null"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="confirmar = null" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="confirmar"
                    class="relative w-full max-w-lg rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <template x-if="confirmar === 'cerrar'">
                        <div>
                            <h2 id="titulo-confirmar" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Cerrar la toma y ajustar el stock</h2>
                            <div class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
                                <p>
                                    Se van a ajustar <b class="text-gray-800 dark:text-white/90" x-text="numero(resumen.con_diferencia)"></b> producto(s) con diferencia:
                                    falta <b class="text-error-600 dark:text-error-400" x-text="'{{ $moneda }} ' + dinero(resumen.faltante)"></b>
                                    y sobra <b class="text-success-700 dark:text-success-500" x-text="'{{ $moneda }} ' + dinero(resumen.sobrante)"></b> al costo.
                                </p>
                                <p x-show="resumen.total > resumen.contados">
                                    <b class="text-warning-700 dark:text-orange-400" x-text="numero(resumen.total - resumen.contados)"></b>
                                    producto(s) sin contar quedan con el stock que tienen.
                                </p>
                                <p>Los ajustes quedan en el kardex. Una toma cerrada ya no se puede modificar.</p>
                            </div>
                            <form method="POST" action="{{ route('tomas.cerrar', $toma) }}" class="mt-6 flex justify-end gap-3">
                                @csrf
                                <x-ui.button type="button" variant="outline" size="sm" @click="confirmar = null">Volver</x-ui.button>
                                <x-ui.button type="submit" size="sm">Cerrar y ajustar</x-ui.button>
                            </form>
                        </div>
                    </template>
                    <template x-if="confirmar === 'cancelar'">
                        <div>
                            <h2 id="titulo-confirmar" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">Cancelar la toma</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                El stock no se toca. Los conteos quedan guardados como constancia, pero no se aplican.
                            </p>
                            <form method="POST" action="{{ route('tomas.cancelar', $toma) }}" class="mt-6 flex justify-end gap-3">
                                @csrf
                                <x-ui.button type="button" variant="outline" size="sm" @click="confirmar = null">Volver</x-ui.button>
                                <x-ui.button type="submit" size="sm" variant="danger">Cancelar toma</x-ui.button>
                            </form>
                        </div>
                    </template>
                </div>
            </div>
        @endif
    </div>

    <script>
        // Punto decimal, igual que Config::importe y Config::cantidad en el resto del sistema.
        const formatoCantidad = new Intl.NumberFormat('en-US', { maximumFractionDigits: 3, useGrouping: false });
        const formatoDinero = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function tomaInventario(resumen) {
            return {
                resumen,
                confirmar: null,

                get porcentaje() {
                    return this.resumen.total > 0 ? Math.floor(this.resumen.contados / this.resumen.total * 100) : 0;
                },

                numero: (n) => formatoCantidad.format(Number(n) || 0),
                dinero: (n) => formatoDinero.format(Number(n) || 0),

                /* F2 lleva al buscador desde cualquier casilla, como en el mostrador. */
                atajo(e) {
                    if (e.key === 'F2') {
                        e.preventDefault();
                        this.$refs.buscador?.focus();
                        this.$refs.buscador?.select();
                    }
                },
            };
        }

        function lineaToma(datos) {
            return {
                url: datos.url,
                valor: datos.contado === null ? '' : String(datos.contado),
                ultimo: datos.contado === null ? '' : String(datos.contado),
                sistema: datos.sistema,
                diferencia: datos.diferencia,
                valorDiferencia: datos.valor,
                guardando: false,
                guardado: false,
                error: '',

                init() {
                    if (datos.enfocar) {
                        this.$nextTick(() => { this.$refs.casilla?.focus(); this.$refs.casilla?.select(); });
                    }
                },

                cantidad: (n) => formatoCantidad.format(Number(n) || 0),
                dinero: (n) => formatoDinero.format(Number(n) || 0),

                /* Guarda solo si cambió. Con Enter, además, vuelve al buscador
                   con el texto seleccionado: el próximo escaneo lo reemplaza. */
                async guardar(volver = false) {
                    const texto = String(this.valor ?? '').trim();

                    if (texto !== this.ultimo) {
                        this.guardando = true;
                        this.error = '';

                        try {
                            const respuesta = await fetch(this.url, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                body: JSON.stringify({ contado: texto === '' ? null : texto }),
                            });
                            const json = await respuesta.json().catch(() => ({}));

                            if (!respuesta.ok) {
                                this.error = json.errors?.contado?.[0] ?? json.message ?? 'No se pudo guardar. Intenta otra vez.';
                                return;
                            }

                            this.ultimo = texto;
                            this.sistema = json.contado === null ? null : json.sistema;
                            this.diferencia = json.diferencia;
                            this.valorDiferencia = json.valor;
                            this.resumen = json.resumen;
                            this.guardado = true;
                            setTimeout(() => { this.guardado = false; }, 1500);
                        } catch {
                            this.error = 'Sin conexión: el conteo no se guardó.';
                            return;
                        } finally {
                            this.guardando = false;
                        }
                    }

                    if (volver) {
                        const buscador = document.getElementById('buscar');
                        buscador?.focus();
                        buscador?.select();
                    }
                },
            };
        }
    </script>
@endsection
