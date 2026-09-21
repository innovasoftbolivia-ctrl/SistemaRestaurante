@extends('layouts.app')

@section('content')
    {{-- El camino de corrección: el pedido cuya venta se anuló vuelve a
         cobrarse aquí, con su mismo número —el cliente ya tiene el ticket y la
         cocina ya lo prepara—, o se cancela. Es más simple que el mostrador y a
         propósito: las líneas NO se agregan —ya están grabadas y en la
         cocina—, solo se puede cancelar un plato que el cliente ya no quiere;
         lo demás es cómo se paga. El total lo calcula el servidor sobre el
         pedido; esta pantalla solo lo anticipa para el vuelto. Las reglas de
         los pagos son las mismas de siempre, porque el destino es el mismo
         `Ventas::registrar()`. --}}
    <div x-data="cobroDePedido()" class="grid grid-cols-1 gap-6 xl:grid-cols-5">

        {{-- ------------------------------------------------------- el pedido --}}
        <div class="space-y-4 xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $pedido->etiqueta }}</h2>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $sesion->caja?->nombre }} · turno de
                        {{ $sesion->usuarioApertura?->usuario ?? auth()->user()->usuario }}
                    </p>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($lineas as $linea)
                        <li class="flex items-start justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ \App\Support\Config::cantidad($linea['cantidad']) }} × {{ $linea['nombre'] }}
                                </p>
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Support\Config::importe($linea['precio_unitario']) }} por porción
                                    @if ($linea['tandas'] > 1)
                                        · {{ $linea['tandas'] }} tandas juntadas
                                    @endif
                                </p>
                            </div>
                            <span class="whitespace-nowrap text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                                {{ \App\Support\Config::importe($linea['cantidad'] * $linea['precio_unitario']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="space-y-1 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                        <span>Subtotal</span>
                        <span>{{ \App\Support\Config::importe($totales['subtotal']) }}</span>
                    </div>

                    @if ($totales['impuesto'] > 0 && \App\Support\Config::facturacionVisible())
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Impuesto{{ $impuestoIncluido ? ' (incluido)' : '' }}</span>
                            <span>{{ \App\Support\Config::importe($totales['impuesto']) }}</span>
                        </div>
                    @endif

                    <div x-show="descuentoValido > 0" x-cloak
                        class="flex justify-between text-theme-sm text-error-600 dark:text-error-400">
                        <span>Descuento</span>
                        <span x-text="'− {{ $moneda }} ' + descuentoValido.toFixed(2)"></span>
                    </div>

                    <div class="flex items-baseline justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Total</span>
                        <span class="text-2xl font-bold text-gray-800 dark:text-white/90"
                            x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                    </div>
                </div>
            </div>

            @if ($cliente)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400" data-cliente-del-cobro>
                    El comprobante sale a nombre de <b class="text-gray-800 dark:text-white/90">{{ $cliente->etiqueta }}</b>,
                    el cliente del cobro que se anuló.
                </div>
            @endif

            {{-- Los platos uno por uno, con su estado en la cocina: el cajero ve
                 qué se está preparando antes de cobrar de nuevo o de cancelar.
                 Lo que la cocina no terminó se puede cancelar (no se cobra, pero
                 el rastro queda); lo listo o entregado ya se sirvió y se cobra. --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-platos-del-pedido>
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Platos del pedido</h2>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        Pedido a las {{ $pedido->fecha_apertura?->format('H:i') }}
                        @if ($pedido->usuario?->usuario)
                            por {{ $pedido->usuario->usuario }}
                        @endif
                        · su cobro se anuló: se vuelve a cobrar con el mismo número.
                    </p>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($pedido->detalle->sortBy('id') as $linea)
                        @php $cancelada = $linea->estado_cocina === \App\Models\PedidoDetalle::CANCELADO; @endphp
                        <li class="flex items-start justify-between gap-3 px-5 py-3 {{ $cancelada ? 'opacity-60' : '' }}">
                            <div class="min-w-0">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90 {{ $cancelada ? 'line-through' : '' }}">
                                    {{ \App\Support\Config::cantidad($linea->cantidad) }} × {{ $linea->descripcion }}
                                </p>
                                @if ($linea->nota)
                                    <p class="mt-0.5 text-theme-xs font-medium text-brand-600 dark:text-brand-400">{{ $linea->nota }}</p>
                                @endif
                                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $linea->estado_visible }}</p>
                            </div>

                            @php $empezado = $linea->estado_cocina === \App\Models\PedidoDetalle::EN_PREPARACION; @endphp
                            @if ($puedeCancelarPlatos && $linea->puedePasarA(\App\Models\PedidoDetalle::CANCELADO))
                                {{-- Lo que la cocina ya empezó lo cancela un administrador, con su motivo. --}}
                                @if (! $empezado)
                                    <form method="POST" action="{{ route('pedidos.lineas.cancelar', [$pedido, $linea]) }}">
                                        @csrf
                                        <button type="submit"
                                            class="rounded-lg px-2 py-1 text-theme-xs font-medium text-error-600 transition hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">
                                            Cancelar plato
                                        </button>
                                    </form>
                                @elseif ($puedeCancelarEmpezados)
                                    <form method="POST" action="{{ route('pedidos.lineas.cancelar', [$pedido, $linea]) }}"
                                        class="flex flex-col items-end gap-1" data-cancelar-empezado>
                                        @csrf
                                        <label for="motivo-linea-{{ $linea->id }}" class="sr-only">Motivo</label>
                                        <input id="motivo-linea-{{ $linea->id }}" name="motivo" required maxlength="255"
                                            placeholder="Motivo"
                                            class="h-8 w-36 rounded-lg border border-gray-300 px-2 text-theme-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                        <button type="submit"
                                            class="rounded-lg px-2 py-1 text-theme-xs font-medium text-error-600 transition hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">
                                            Cancelar plato
                                        </button>
                                    </form>
                                @else
                                    <span class="max-w-36 text-right text-theme-xs text-gray-500 dark:text-gray-400">
                                        Ya se prepara: solo lo cancela un administrador.
                                    </span>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($avisoPendiente)
                {{-- Un plato que la cocina ya tenía en papel se canceló: hay que
                     avisarle en papel, o lo prepara igual. --}}
                <form method="POST" action="{{ route('pedidos.comanda.imprimir', $pedido) }}" target="_blank" data-aviso-cocina>
                    @csrf
                    <button type="submit"
                        class="w-full rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-theme-sm font-medium text-warning-800 transition hover:bg-warning-100 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                        Imprimir el aviso de cancelación para la cocina
                    </button>
                </form>
            @endif

            @if ($puedeCancelar)
                <button type="button" @click="cancelando = true"
                    class="w-full rounded-lg px-4 py-3 text-theme-sm font-medium text-error-600 transition hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">
                    Cancelar el pedido
                </button>
            @elseif ($puedeCancelarPlatos)
                {{-- Lo que la cocina ya tocó se cobra o lo cancela quien puede anular (C1). --}}
                <p class="px-1 text-center text-theme-xs text-gray-500 dark:text-gray-400">
                    La cocina ya empezó o se entregaron platos de este pedido: solo lo cancela un administrador.
                </p>
            @endif
        </div>

        {{-- Cancelar el pedido entero --}}
        @if ($puedeCancelar)
            <div x-show="cancelando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-cancelar"
                @keydown.escape.window="cancelando = false"
                class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                <div @click="cancelando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                <div x-trap.inert.noscroll="cancelando"
                    class="relative max-h-[90vh] w-full max-w-md overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                    <h2 id="titulo-modal-cancelar" class="mb-3 text-xl font-semibold text-gray-800 dark:text-white/90">
                        Cancelar el {{ $pedido->numero_visible }}
                    </h2>
                    <p class="mb-5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Nada de este pedido se cobra y sale de la cocina. El pedido no se borra: queda con su motivo y
                        tu nombre, para que se pueda explicar después.
                    </p>
                    @if ($platosEmpezados > 0)
                        <p class="mb-5 rounded-xl bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:bg-orange-500/10 dark:text-orange-400">
                            La cocina ya empezó, terminó o entregó {{ $platosEmpezados }} plato(s) de este pedido: al
                            cancelarlo quedan sin cobrar. Si el cliente los consumió, cóbralo.
                        </p>
                    @endif

                    <form method="POST" action="{{ route('pedidos.cancelar', $pedido) }}" class="space-y-5">
                        @csrf

                        <x-form.campo label="Motivo" for="cancelar-motivo" name="motivo" required>
                            <x-form.input id="cancelar-motivo" name="motivo" :value="old('motivo')"
                                placeholder="El cliente se fue sin esperar su pedido" maxlength="255" required />
                        </x-form.campo>

                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="outline" size="sm" @click="cancelando = false">Volver</x-ui.button>
                            <x-ui.button type="submit" variant="danger" size="sm">Cancelar el pedido</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- --------------------------------------------------- formas de pago --}}
        <div class="xl:col-span-3">
            <form method="POST" action="{{ route('pedidos.cobrar.store', $pedido) }}" x-ref="cobro"
                @submit.prevent="enviar()"
                class="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                @csrf
                @unEnvio
                <div x-ref="campos"></div>

                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">¿Cómo paga?</h2>

                <template x-for="(pago, i) in pagos" :key="i">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                                x-text="'Forma ' + (i + 1)"></span>
                            <button type="button" x-show="pagos.length > 1" @click="quitarPago(i)"
                                class="text-theme-xs font-medium text-error-600 hover:underline dark:text-error-400">Quitar</button>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <template x-for="m in metodos" :key="m.id">
                                <button type="button" @click="cambiarMetodo(pago, m.id)"
                                    :class="pago.metodoId === m.id
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10'"
                                    class="min-h-11 rounded-lg px-4 py-3 text-theme-xs font-medium transition"
                                    x-text="m.nombre"></button>
                            </template>
                        </div>

                        {{-- El importe solo hace falta cuando hay más de una forma:
                             con una sola cubre el total y punto. --}}
                        <div x-show="pagos.length > 1" class="mt-3">
                            <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                Importe
                                <span x-show="vacio(pago)" class="text-brand-500 dark:text-brand-400">— el resto</span>
                            </label>
                            <input type="number" inputmode="decimal" step="0.01" min="0" x-model="pago.monto"
                                :placeholder="montoDe(pago).toFixed(2)"
                                class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                Cubre <span class="font-medium" x-text="'{{ $moneda }} ' + montoDe(pago).toFixed(2)"></span>.
                                Déjalo vacío para que tome lo que falte.
                            </p>
                        </div>

                        <div x-show="esEfectivo(pago)" class="mt-3">
                            <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                Efectivo recibido
                            </label>
                            <input type="number" inputmode="decimal" step="0.01" min="0" x-model.number="pago.recibido"
                                :placeholder="montoDe(pago).toFixed(2)"
                                class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />

                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <template x-for="s in sugerenciasDe(pago)" :key="s">
                                    <button type="button" @click="pago.recibido = s"
                                        class="min-h-11 rounded-lg bg-gray-100 px-3 py-3 text-theme-xs text-gray-600 transition hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10"
                                        x-text="'{{ $moneda }} ' + s.toFixed(2)"></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="!esEfectivo(pago) && !esQr(pago)" class="mt-3">
                            <label class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                Número de operación
                            </label>
                            <input type="text" x-model="pago.referencia"
                                :placeholder="exigeReferencia ? 'El del voucher o comprobante' : 'Opcional'"
                                :aria-invalid="faltaReferencia(pago) ? 'true' : 'false'"
                                class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </div>

                        {{-- Cobro por QR: el mismo circuito del mostrador (las rutas
                             `pos/qr*`), con el importe ya puesto en el código. La venta
                             no existe todavía: se registra con el pago confirmado. --}}
                        <div x-show="esQr(pago)" class="mt-3">
                            <template x-if="!pago.qr">
                                <div>
                                    <button type="button" @click="generarQr(pago)"
                                        :disabled="montoDe(pago) <= 0 || pago.qrCargando"
                                        class="w-full rounded-lg bg-brand-500 px-3 py-2.5 text-theme-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-white/10">
                                        <span x-show="!pago.qrCargando"
                                            x-text="'Generar QR por {{ $moneda }} ' + montoDe(pago).toFixed(2)"></span>
                                        <span x-show="pago.qrCargando">Generando…</span>
                                    </button>
                                </div>
                            </template>

                            <template x-if="pago.qr">
                                <div class="rounded-xl border border-gray-200 p-3 text-center dark:border-gray-800">
                                    <div x-show="!pago.qr.pagado" class="flex flex-col items-center">
                                        <template x-if="pago.qr.imagen">
                                            <img :src="pago.qr.payload" alt="Código QR para pagar" width="260" height="260"
                                                class="h-[260px] w-[260px] rounded-lg bg-white p-2" />
                                        </template>
                                        <template x-if="!pago.qr.imagen">
                                            <canvas :id="'qr-pedido-' + pago.qr.id" class="rounded-lg bg-white p-2"></canvas>
                                        </template>

                                        <p x-show="qrDesfasado(pago)" role="alert"
                                            class="mt-2 rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400"
                                            x-text="'Este QR es por {{ $moneda }} ' + pago.qr.monto.toFixed(2) + ' y el importe de esta forma es {{ $moneda }} ' + montoDe(pago).toFixed(2) + '. Cancélalo y genera otro.'"></p>

                                        <p class="mt-2 text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                            x-text="'{{ $moneda }} ' + pago.qr.monto.toFixed(2)"></p>
                                        <p class="mt-0.5 flex items-center justify-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                            <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span>
                                            <span x-text="pago.qr.etiqueta"></span>
                                        </p>

                                        <p x-show="pago.qr.simulado"
                                            class="mt-2 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-orange-500/10 dark:text-orange-400">
                                            Sin banco conectado: el pago se confirma a mano.
                                        </p>

                                        <div class="mt-3 flex w-full flex-wrap gap-2">
                                            <button type="button" @click="confirmarQr(pago)"
                                                class="flex-1 rounded-lg bg-success-500 px-3 py-2 text-theme-xs font-medium text-white transition hover:bg-success-600"
                                                x-text="pago.qr.simulado ? 'Ya me pagó' : 'Verificar pago'"></button>
                                            <button type="button" @click="anularQr(pago)"
                                                class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">
                                                Cancelar
                                            </button>
                                        </div>
                                    </div>

                                    <div x-show="pago.qr.pagado" class="py-3">
                                        <p class="text-lg font-semibold text-success-700 dark:text-success-500">Pago confirmado</p>
                                        <p x-show="qrDesfasado(pago)" role="alert"
                                            class="mt-2 rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400"
                                            x-text="'El cliente pagó {{ $moneda }} ' + pago.qr.monto.toFixed(2) + ' y esta forma cubre {{ $moneda }} ' + montoDe(pago).toFixed(2) + '. Ajusta el reparto.'"></p>
                                        <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400"
                                            x-text="'{{ $moneda }} ' + pago.qr.monto.toFixed(2)"></p>
                                        <p x-show="pago.qr.referencia" class="mt-1 font-mono text-theme-xs text-gray-500 dark:text-gray-400"
                                            x-text="pago.qr.referencia"></p>
                                    </div>
                                </div>
                            </template>

                            <p x-show="pago.qrError" class="mt-2 text-theme-xs text-error-600 dark:text-error-400"
                                x-text="pago.qrError"></p>
                        </div>
                    </div>
                </template>

                <button type="button" @click="agregarPago()" x-show="pagos.length < metodos.length && total > 0"
                    class="min-h-11 w-full rounded-lg border border-dashed border-gray-300 px-3 py-3 text-theme-xs font-medium text-gray-500 transition hover:border-brand-400 hover:text-brand-500 dark:border-gray-700 dark:text-gray-400">
                    + Dividir el pago en otra forma
                </button>

                <div x-show="pagos.length > 1 && lineasSinMonto === 0 && Math.abs(restante) >= 0.005" x-cloak
                    class="flex items-baseline justify-between rounded-xl px-4 py-3"
                    :class="restante > 0 ? 'bg-warning-50 dark:bg-orange-500/10' : 'bg-error-50 dark:bg-error-500/10'">
                    <span class="text-theme-sm font-medium"
                        :class="restante > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-error-600 dark:text-error-400'"
                        x-text="restante > 0 ? 'Falta por asignar' : 'Asignado de más'"></span>
                    <span class="text-lg font-semibold"
                        :class="restante > 0 ? 'text-warning-700 dark:text-orange-400' : 'text-error-600 dark:text-error-400'"
                        x-text="'{{ $moneda }} ' + Math.abs(restante).toFixed(2)"></span>
                </div>

                <div x-show="vuelto > 0" x-cloak
                    class="flex items-baseline justify-between rounded-xl bg-success-50 px-4 py-3 dark:bg-success-500/10">
                    <span class="text-theme-sm font-medium text-success-700 dark:text-success-500">Vuelto</span>
                    <span class="text-lg font-semibold text-success-700 dark:text-success-500"
                        x-text="'{{ $moneda }} ' + vuelto.toFixed(2)"></span>
                </div>

                {{-- Descuento: el mismo tope por rol que en el mostrador, y el
                     servidor lo vuelve a comprobar con el pedido en la mano. --}}
                <div class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <label for="pedido-descuento" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                        Descuento
                        @unless ($puedeDescontar)
                            <span class="text-gray-400">(hasta {{ (int) $descuentoMaximo }}% sin autorización)</span>
                        @endunless
                    </label>
                    <input id="pedido-descuento" type="number" inputmode="decimal" step="0.01" min="0"
                        x-model.number="descuento"
                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    <p x-show="excedeDescuento && !puedeDescontar" x-cloak
                        class="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                        Ese descuento supera tu máximo: pídele a un administrador que cobre el pedido.
                    </p>
                </div>

                <div>
                    <label for="pedido-observacion" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                        Observación
                    </label>
                    <input id="pedido-observacion" name="observacion" type="text" maxlength="255"
                        value="{{ old('observacion', $pedido->observacion) }}"
                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </div>

                <button type="submit" :disabled="!puedeCobrar || enviando"
                    class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 text-base font-semibold text-white transition hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-500 dark:disabled:bg-white/[0.06] dark:disabled:text-gray-500">
                    <span x-show="enviando">Cobrando…</span>
                    <span x-show="!enviando && puedeCobrar" x-text="'Cobrar {{ $moneda }} ' + total.toFixed(2)"></span>
                    <span x-show="!enviando && !puedeCobrar" x-text="motivoBloqueo || 'Cobrar'"></span>
                </button>

                <a href="{{ route('pos.index') }}"
                    class="block text-center text-theme-sm text-gray-500 underline dark:text-gray-400">
                    Volver al punto de venta
                </a>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            function cobroDePedido() {
                return {
                    /* El total lo calculó el servidor sobre el pedido; aquí solo se
                       le resta el descuento para el reparto y el vuelto. La cifra
                       que vale sigue siendo la de la venta: se manda como
                       `total_esperado` y el servidor rechaza el cobro si no cuadra. */
                    totalBase: {{ $totales['total'] }},
                    subtotal: {{ $totales['subtotal'] }},
                    descuento: {{ (float) old('descuento', 0) }},
                    maxDescuento: {{ $descuentoMaximo }},
                    puedeDescontar: {{ $puedeDescontar ? 'true' : 'false' }},
                    enviando: false,
                    cancelando: {{ $errors->has('motivo') ? 'true' : 'false' }},

                    metodos: @js($metodosPago->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre])->values()),
                    {{-- Lo que entra al cajón admite vuelto: `afecta_caja`, como el arqueo. --}}
                    efectivos: @js($metodosPago->where('afecta_caja', true)->pluck('id')->values()),
                    metodosQr: @js($metodosQr),
                    exigeReferencia: @js($exigeReferencia),
                    qrSegundos: {{ $qrSegundosConsulta }},
                    rutaQrCrear: '{{ route('qr.crear') }}',
                    rutaQr: '{{ url('pos/qr') }}',

                    pagos: [{
                        metodoId: {{ $metodosPago->first()?->id ?? 'null' }},
                        monto: '',
                        recibido: null,
                        referencia: '',
                        qr: null,
                        qrCargando: false,
                        qrError: '',
                    }],

                    redondear(n) {
                        return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
                    },

                    /* El descuento nunca puede pasarse del total: la base lo
                       rechazaría y el cajero se quedaría sin saber por qué. */
                    get descuentoValido() {
                        return Math.min(Math.max(Number(this.descuento) || 0, 0), this.totalBase);
                    },

                    get total() {
                        return this.redondear(this.totalBase - this.descuentoValido);
                    },

                    get excedeDescuento() {
                        if (this.descuentoValido <= 0 || this.subtotal <= 0) return false;
                        // En centavos, igual que el servidor.
                        return Math.round(this.descuentoValido * 100) * 100 > this.maxDescuento * Math.round(this.subtotal * 100);
                    },

                    /* ---------------------------------------- formas de pago */

                    esEfectivo(pago) { return this.efectivos.includes(pago.metodoId); },
                    esQr(pago) { return this.metodosQr.includes(pago.metodoId); },
                    vacio(pago) { return pago.monto === '' || pago.monto === null; },

                    get lineasSinMonto() {
                        return this.pagos.filter(p => this.vacio(p)).length;
                    },

                    get asignado() {
                        return this.redondear(this.pagos.reduce(
                            (suma, p) => suma + (this.vacio(p) ? 0 : Number(p.monto) || 0), 0));
                    },

                    get restante() {
                        return this.redondear(this.total - this.asignado);
                    },

                    montoDe(pago) {
                        if (!this.vacio(pago)) return this.redondear(Number(pago.monto) || 0);

                        return this.lineasSinMonto === 1 ? Math.max(this.restante, 0) : 0;
                    },

                    vueltoDe(pago) {
                        if (!this.esEfectivo(pago) || pago.recibido === null || pago.recibido === '') return 0;

                        return this.redondear(Math.max((Number(pago.recibido) || 0) - this.montoDe(pago), 0));
                    },

                    get vuelto() {
                        return this.redondear(this.pagos.reduce((s, p) => s + this.vueltoDe(p), 0));
                    },

                    agregarPago() {
                        const usados = this.pagos.map(p => p.metodoId);
                        const libre = this.metodos.find(m => !usados.includes(m.id));

                        // Al abrir una segunda línea, la primera deja de ser «el
                        // resto» y toma un importe concreto: si no, quedarían dos
                        // en blanco y no se sabría cuál cubre qué.
                        if (this.lineasSinMonto >= 1) {
                            this.pagos.forEach(p => {
                                if (this.vacio(p)) p.monto = this.montoDe(p).toFixed(2);
                            });
                        }

                        this.pagos.push({
                            metodoId: libre ? libre.id : this.metodos[0].id,
                            monto: '', recibido: null, referencia: '',
                            qr: null, qrCargando: false, qrError: '',
                        });
                    },

                    quitarPago(i) {
                        const pago = this.pagos[i];
                        if (pago.qr && !pago.qr.pagado) this.anularQr(pago);
                        if (pago.qr && pago.qr.pagado) {
                            pago.qrError = 'Ese QR ya está pagado: el dinero está en el banco. No quites esta forma.';
                            return;
                        }
                        this.pagos.splice(i, 1);
                    },

                    faltaReferencia(pago) {
                        return this.exigeReferencia && !this.esEfectivo(pago) && !this.esQr(pago)
                            && !String(pago.referencia || '').trim();
                    },

                    qrPendiente(pago) {
                        return this.esQr(pago) && !(pago.qr && pago.qr.pagado);
                    },

                    qrDesfasado(pago) {
                        return this.esQr(pago) && !!pago.qr && Math.abs(Number(pago.qr.monto) - this.montoDe(pago)) > 0.001;
                    },

                    cambiarMetodo(pago, metodoId) {
                        if (pago.metodoId === metodoId) return;
                        if (pago.qr && !pago.qr.pagado) this.anularQr(pago);
                        if (pago.qr && pago.qr.pagado && !this.metodosQr.includes(metodoId)) {
                            pago.qrError = 'Ese QR ya está pagado: el dinero está en el banco. Déjalo como pago por QR.';
                            return;
                        }
                        pago.metodoId = metodoId;
                    },

                    /* El mismo tope que el servidor: un vuelto igual o mayor al billete

                       más grande es casi siempre un cero de más al teclear. */

                    billeteMayor: {{ \App\Services\Ventas::billeteMayor() }},


                    vueltoExcesivo(pago) {

                        return this.esEfectivo(pago)

                            && pago.recibido !== null && pago.recibido !== ''

                            && this.redondear(Number(pago.recibido) - this.montoDe(pago)) >= this.billeteMayor;

                    },


                    efectivoCorto(pago) {
                        return this.esEfectivo(pago)
                            && pago.recibido !== null && pago.recibido !== ''
                            && Number(pago.recibido) < this.montoDe(pago);
                    },

                    get pagoCubierto() {
                        if (this.lineasSinMonto > 1) return false;

                        return this.lineasSinMonto === 1
                            ? this.restante > 0
                            : Math.abs(this.asignado - this.total) < 0.005;
                    },

                    get puedeCobrar() {
                        if (this.total <= 0) return false;
                        if (this.excedeDescuento && !this.puedeDescontar) return false;
                        if (!this.pagoCubierto) return false;
                        if (this.pagos.some(p => this.efectivoCorto(p))) return false;
                        if (this.pagos.some(p => this.vueltoExcesivo(p))) return false;
                        if (this.pagos.some(p => this.qrPendiente(p))) return false;
                        if (this.pagos.some(p => this.qrDesfasado(p))) return false;
                        if (this.pagos.some(p => this.faltaReferencia(p))) return false;

                        return true;
                    },

                    get motivoBloqueo() {
                        if (this.excedeDescuento && !this.puedeDescontar) return 'El descuento necesita autorización.';
                        if (this.lineasSinMonto > 1) return 'Solo una forma de pago puede quedar sin importe.';
                        if (this.lineasSinMonto === 1 && this.restante <= 0) return 'Las formas de pago ya cubren el total.';
                        if (this.lineasSinMonto === 0 && this.restante > 0) {
                            return 'Faltan {{ $moneda }} ' + this.restante.toFixed(2) + ' por asignar.';
                        }
                        if (this.lineasSinMonto === 0 && this.restante < 0) {
                            return 'Las formas de pago suman {{ $moneda }} ' + Math.abs(this.restante).toFixed(2) + ' de más.';
                        }
                        if (this.pagos.some(p => this.efectivoCorto(p))) return 'El efectivo recibido no alcanza.';
                        if (this.pagos.some(p => this.vueltoExcesivo(p))) return 'El efectivo recibido deja un vuelto demasiado grande: revisa lo que tecleaste.';
                        if (this.pagos.some(p => this.qrDesfasado(p))) return 'El QR es por otro importe.';
                        if (this.pagos.some(p => this.qrPendiente(p))) return 'Falta que se confirme el pago por QR.';
                        if (this.pagos.some(p => this.faltaReferencia(p))) return 'Falta el número de operación del voucher.';
                        return '';
                    },

                    sugerenciasDe(pago) {
                        const t = this.montoDe(pago);
                        if (t <= 0) return [];

                        const billetes = [10, 20, 50, 100, 200];
                        const opciones = new Set([Math.ceil(t)]);
                        // Sin los que dejarían un vuelto que el servidor rechaza

                        // (igual o mayor al billete más grande: `Ventas::billeteMayor`).

                        billetes.filter(b => b >= t && this.redondear(b - t) < this.billeteMayor).forEach(b => opciones.add(b));

                        return [...opciones].sort((a, b) => a - b).slice(0, 4);
                    },

                    /* ------------------------------------------- cobro por QR */

                    get cabecera() {
                        return {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        };
                    },

                    async generarQr(pago) {
                        const monto = this.montoDe(pago);
                        if (monto <= 0 || pago.qrCargando) return;

                        pago.qrCargando = true;
                        pago.qrError = '';

                        try {
                            const r = await fetch(this.rutaQrCrear, {
                                method: 'POST',
                                headers: this.cabecera,
                                body: JSON.stringify({ monto: monto.toFixed(2), glosa: @js($pedido->etiqueta) }),
                            });
                            const datos = await r.json();

                            if (!r.ok) {
                                pago.qrError = datos.error ?? 'No se pudo generar el QR.';
                                return;
                            }

                            pago.qr = datos;
                            this.$nextTick(() => this.pintarQr(pago));
                            this.vigilarQr(pago);
                        } catch (e) {
                            pago.qrError = 'No se pudo generar el QR.';
                        } finally {
                            pago.qrCargando = false;
                        }
                    },

                    pintarQr(pago) {
                        if (pago.qr.imagen) return;
                        const lienzo = document.getElementById('qr-pedido-' + pago.qr.id);
                        if (lienzo && window.dibujarQr) window.dibujarQr(lienzo, pago.qr.payload);
                    },

                    /* Le pregunta al banco cada pocos segundos si ya pagaron, y se
                       detiene cuando el cobro deja de estar pendiente. */
                    vigilarQr(pago) {
                        if (!pago.qr || pago.qr.estado !== 'PENDIENTE') return;

                        const id = pago.qr.id;

                        setTimeout(async () => {
                            if (!pago.qr || pago.qr.id !== id) return;

                            try {
                                const r = await fetch(this.rutaQr + '/' + id, { headers: { 'Accept': 'application/json' } });
                                if (r.ok) pago.qr = await r.json();
                            } catch (e) {
                                /* Sin banco: se reintenta en la vuelta siguiente. */
                            }

                            this.vigilarQr(pago);
                        }, this.qrSegundos * 1000);
                    },

                    async confirmarQr(pago) {
                        if (!pago.qr) return;

                        const r = await fetch(this.rutaQr + '/' + pago.qr.id + '/confirmar', {
                            method: 'POST',
                            headers: this.cabecera,
                            body: JSON.stringify({ referencia: pago.referencia || null }),
                        });
                        const datos = await r.json();

                        if (r.ok) pago.qr = datos;
                        else pago.qrError = datos.error ?? 'No se pudo confirmar el pago.';
                    },

                    async anularQr(pago) {
                        if (!pago.qr) return true;

                        try {
                            const r = await fetch(this.rutaQr + '/' + pago.qr.id + '/anular', {
                                method: 'POST',
                                headers: this.cabecera,
                            });
                            const datos = await r.json().catch(() => ({}));

                            if (!r.ok) {
                                const consulta = await fetch(this.rutaQr + '/' + pago.qr.id, { headers: { 'Accept': 'application/json' } });
                                if (consulta.ok) pago.qr = await consulta.json();
                                pago.qrError = datos.error ?? 'No se pudo cancelar el QR.';
                                return false;
                            }
                        } catch (e) {
                            pago.qrError = 'No se pudo cancelar el QR: revisa la conexión y vuelve a intentar.';
                            return false;
                        }

                        pago.qr = null;
                        pago.qrError = '';
                        return true;
                    },

                    /* Los pagos viven en Alpine: el formulario se arma al enviar. */
                    enviar() {
                        if (!this.puedeCobrar || this.enviando) return;

                        this.enviando = true;

                        const campos = this.$refs.campos;
                        campos.innerHTML = '';

                        const oculto = (name, value) => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = name;
                            input.value = value;
                            campos.appendChild(input);
                        };

                        oculto('total_esperado', this.total.toFixed(2));
                        if (this.descuentoValido > 0) oculto('descuento', this.descuentoValido.toFixed(2));

                        this.pagos.forEach((p, i) => {
                            oculto(`pagos[${i}][metodo_pago_id]`, p.metodoId);

                            /* De la línea que va «por el resto» NO se manda el
                               importe: lo calcula el servidor sobre su propio total,
                               así un céntimo de redondeo no tumba el cobro. */
                            if (!this.vacio(p)) {
                                oculto(`pagos[${i}][monto]`, this.montoDe(p).toFixed(2));
                            }

                            if (this.esEfectivo(p)) {
                                if (p.recibido !== null && p.recibido !== '' && Number(p.recibido) >= this.montoDe(p)) {
                                    oculto(`pagos[${i}][monto_recibido]`, Number(p.recibido).toFixed(2));
                                }
                            } else if (p.referencia) {
                                oculto(`pagos[${i}][referencia]`, p.referencia);
                            }

                            if (this.esQr(p) && p.qr && p.qr.pagado) {
                                oculto(`pagos[${i}][cobro_qr_id]`, p.qr.id);
                            }
                        });

                        this.$refs.cobro.submit();
                    },
                };
            }
        </script>
    @endpush
@endsection
