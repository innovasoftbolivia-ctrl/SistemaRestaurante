@extends('layouts.app')

@php
    use App\Support\Config;

    $comprobante = $venta->comprobante;
    $anulada = $venta->estado === 'ANULADA';
@endphp

@section('content')
    <div x-data="{ anulando: false, sustituyendo: {{ $errors->any() && old('motivo') !== null ? 'true' : 'false' }} }"
        @keydown.escape.window="anulando = false; sustituyendo = false" class="space-y-6">

        {{-- Cabecera --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="mb-2 flex flex-wrap items-center gap-3">
                        <h2 class="font-mono text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ $comprobante?->numero_completo ?? 'Venta #'.$venta->id }}
                        </h2>
                        <x-ui.estado :estado="$anulada ? 'CESADO' : 'ACTIVO'"
                            :texto="ucfirst(str_replace('_', ' ', mb_strtolower($venta->estado)))" />
                        @if ($comprobante)
                            <x-ui.estado estado="INDEFINIDO" :texto="$comprobante->nombre_tipo" />
                        @endif
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $venta->fecha?->format('d/m/Y H:i') }} ·
                        cajero {{ $venta->usuario?->usuario }} ·
                        {{ $venta->sesionCaja?->caja?->nombre }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($comprobante)
                        <x-ui.button size="sm" :href="route('comprobantes.imprimir', $comprobante)" target="_blank">
                            Imprimir ticket
                        </x-ui.button>
                        <x-ui.button size="sm" variant="outline" target="_blank"
                            :href="route('comprobantes.imprimir', [$comprobante, 'formato' => 'a4'])">
                            Ver en A4
                        </x-ui.button>
                    @endif

                    @puede('ventas.anular')
                        @if ($puedeSustituir)
                            <x-ui.button size="sm" variant="outline" @click="sustituyendo = true">
                                Sustituir comprobante
                            </x-ui.button>
                        @endif
                    @endpuede

                    @puede('devoluciones.registrar')
                        @if ($venta->admiteDevolucion())
                            <x-ui.button size="sm" variant="outline" :href="route('devoluciones.create', $venta)">
                                Registrar devolución
                            </x-ui.button>
                        @endif
                    @endpuede

                    @puede('ventas.anular')
                        @if ($venta->puedeAnularse())
                            <x-ui.button size="sm" variant="danger" @click="anulando = true">Anular venta</x-ui.button>
                        @endif
                    @endpuede
                </div>
            </div>

            @if ($anulada)
                <div class="mt-5">
                    <x-ui.alert variant="error" title="Venta anulada"
                        :message="'Anulada por '.($venta->anuladaPor?->usuario ?? '—').' el '.$venta->anulada_en?->format('d/m/Y H:i').'. Motivo: '.$venta->motivo_anulacion" />
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Detalle --}}
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="px-6 py-5">
                        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            El nombre y el precio son copia del momento de la venta: si el catálogo cambia después,
                            este documento no se altera.
                        </p>
                    </div>

                    <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cantidad</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">P. unitario</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Importe</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Impuesto</th>
                                    <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($venta->detalle as $linea)
                                    <tr>
                                        <td class="px-5 py-4">
                                            <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                                {{ $linea->descripcion }}
                                            </span>
                                            <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $linea->producto?->codigo }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $linea->cantidad_con_unidad }}
                                            {{ $linea->producto?->unidadMedida?->codigo }}
                                            @if ((float) $linea->cantidad_devuelta > 0)
                                                <span class="block text-theme-xs text-error-600 dark:text-error-400">
                                                    {{ Config::cantidad($linea->cantidad_devuelta) }} devuelta(s)
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ Config::importe($linea->precio_unitario) }}
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ Config::importe($linea->importe) }}
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $linea->afecto_impuesto ? Config::importe($linea->impuesto_linea) : 'exonerado' }}
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ Config::importe($linea->total_linea) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-2 border-t border-gray-100 px-6 py-5 dark:border-gray-800">
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Subtotal (base imponible)</span>
                            <span>{{ Config::importe($venta->subtotal) }}</span>
                        </div>
                        @if ((float) $venta->descuento > 0)
                            <div class="flex justify-between text-theme-sm text-error-600 dark:text-error-400">
                                <span>Descuento</span>
                                <span>− {{ Config::importe($venta->descuento) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Impuesto</span>
                            <span>{{ Config::importe($venta->impuesto) }}</span>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                            <span class="font-medium text-gray-800 dark:text-white/90">Total</span>
                            <span class="text-title-sm font-semibold text-brand-500 dark:text-brand-400">{{ Config::importe($venta->total) }}</span>
                        </div>

                        @if ((float) $venta->total_devuelto > 0)
                            <div class="flex justify-between text-theme-sm text-error-600 dark:text-error-400">
                                <span>Devuelto al cliente</span>
                                <span>− {{ Config::importe($venta->total_devuelto) }}</span>
                            </div>
                            <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                                <span>Neto de la operación</span>
                                <span>{{ Config::importe((float) $venta->total - (float) $venta->total_devuelto) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <x-common.component-card title="Cliente">
                    @if ($venta->cliente)
                        <dl class="space-y-3">
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Nombre</dt>
                                <dd class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $venta->cliente->nombre }}
                                </dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Documento</dt>
                                <dd class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $venta->cliente->tipo_documento }} {{ $venta->cliente->documento ?: '—' }}
                                </dd>
                            </div>
                            @if ($venta->cliente->direccion)
                                <div>
                                    <dt class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Dirección</dt>
                                    <dd class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $venta->cliente->direccion }}</dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Venta al paso, sin cliente registrado. El comprobante sale a nombre genérico.
                        </p>
                    @endif
                </x-common.component-card>

                <x-common.component-card title="Cobro">
                    @foreach ($venta->pagos as $pago)
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $pago->metodoPago?->nombre }}</p>
                                @if ($pago->referencia)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">op. {{ $pago->referencia }}</p>
                                @endif
                                @if ($pago->monto_recibido !== null)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        recibido {{ Config::importe($pago->monto_recibido) }}
                                    </p>
                                @endif
                            </div>
                            <span class="whitespace-nowrap text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ Config::importe($pago->monto) }}
                            </span>
                        </div>
                    @endforeach

                    @if ($venta->vuelto > 0)
                        <div class="flex justify-between border-t border-gray-100 pt-3 text-theme-sm dark:border-gray-800">
                            <span class="text-gray-500 dark:text-gray-400">Vuelto</span>
                            <span class="font-medium text-success-700 dark:text-success-500">
                                {{ Config::importe($venta->vuelto) }}
                            </span>
                        </div>
                    @endif
                </x-common.component-card>

                @if ($venta->devoluciones->isNotEmpty())
                    <x-common.component-card title="Devoluciones"
                        desc="Cada una revirtió stock y sacó dinero del cajón del turno en que se registró.">
                        @foreach ($venta->devoluciones->sortByDesc('fecha') as $devolucion)
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('devoluciones.show', $devolucion) }}"
                                        class="text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        Devolución #{{ $devolucion->id }} · {{ mb_strtolower($devolucion->tipo) }}
                                    </a>
                                    <p class="line-clamp-2 text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $devolucion->fecha?->format('d/m/Y H:i') }} · {{ $devolucion->motivo }}
                                    </p>
                                </div>
                                <span class="whitespace-nowrap text-theme-sm font-medium text-error-600 dark:text-error-400">
                                    − {{ Config::importe($devolucion->total) }}
                                </span>
                            </div>
                        @endforeach
                    </x-common.component-card>
                @endif

                @if ($venta->comprobantes->isNotEmpty())
                    @php $porId = $venta->comprobantes->keyBy('id'); @endphp

                    <x-common.component-card title="Documentos"
                        desc="Nada se borra: un documento se anula o se sustituye, y la cadena queda a la vista.">
                        @foreach ($venta->comprobantes->sortByDesc('id') as $doc)
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('comprobantes.imprimir', $doc) }}" target="_blank"
                                        class="font-mono text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $doc->numero_completo }}
                                    </a>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $doc->nombre_tipo }} · {{ $doc->fecha_emision?->format('d/m/Y H:i') }}
                                    </p>
                                    @if ($doc->sustituye_a)
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            Reemplaza a {{ $porId[$doc->sustituye_a]?->numero_completo ?? '—' }}
                                            @if ($doc->motivo_emision)
                                                · {{ $doc->motivo_emision }}
                                            @endif
                                        </p>
                                    @endif
                                    @if ($doc->estado === 'SUSTITUIDO' && $doc->sustituido_en)
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            Sustituido el {{ $doc->sustituido_en->format('d/m/Y H:i') }}
                                        </p>
                                    @endif
                                </div>
                                <x-ui.estado
                                    :estado="match ($doc->estado) { 'EMITIDO' => 'ACTIVO', 'ANULADO' => 'CESADO', default => 'SUSPENDIDO' }"
                                    :texto="ucfirst(mb_strtolower($doc->estado))" />
                            </div>
                        @endforeach

                        @if ($comprobante)
                            @if ($puedeSustituir)
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                    Se puede sustituir hasta el {{ $venceSustitucion->format('d/m/Y') }}.
                                </p>
                            @elseif ($bloqueoSustitucion)
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $bloqueoSustitucion }}
                                </p>
                            @endif
                        @endif
                    </x-common.component-card>
                @endif
            </div>
        </div>

        {{-- Sustituir comprobante --}}
        @puede('ventas.anular')
            @if ($puedeSustituir)
                <div x-show="sustituyendo" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-sustituir-comprobante"
                    class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                    <div @click="sustituyendo = false"
                        class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                    <div x-trap.inert.noscroll="sustituyendo"
                        class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                        <h2 id="titulo-modal-sustituir-comprobante" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                            Sustituir comprobante
                        </h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            El caso habitual: se entregó <b>{{ $comprobante->nombre_tipo }}
                            {{ $comprobante->numero_completo }}</b> y el cliente vuelve pidiendo factura. La venta no
                            se toca —el dinero ya se cobró—: solo cambia el documento. El actual queda
                            <b>sustituido</b>, conserva su número, y el nuevo lo referencia.
                        </p>

                        <form method="POST" action="{{ route('comprobantes.sustituir', $comprobante) }}"
                            x-data="{ cliente: '{{ old('cliente_id', $venta->cliente_id) }}' }" class="space-y-5">
                            @csrf

                            <x-form.campo label="Cliente del nuevo documento" for="sustituir_cliente" name="cliente_id"
                                help="Una persona jurídica hace que salga factura; sin cliente o con persona natural, recibo.">
                                <select id="sustituir_cliente" name="cliente_id" x-model="cliente"
                                    class="dark:bg-dark-900 shadow-theme-xs h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                    <option value="">Sin cliente registrado — recibo</option>
                                    @foreach ($clientes as $c)
                                        <option value="{{ $c['id'] }}">
                                            {{ $c['etiqueta'] }}{{ $c['juridica'] ? ' — factura' : ' — recibo' }}
                                        </option>
                                    @endforeach
                                </select>
                            </x-form.campo>

                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                ¿El cliente no está registrado?
                                <a href="{{ route('clientes.index') }}" class="text-brand-500 dark:text-brand-400 hover:text-brand-600">
                                    Regístralo primero
                                </a>, con su NIT y dirección fiscal.
                            </p>

                            <x-form.campo label="Motivo" for="sustituir_motivo" name="motivo" required
                                help="Queda guardado en el documento nuevo.">
                                <x-form.textarea id="sustituir_motivo" name="motivo"
                                    placeholder="El cliente solicitó factura a nombre de su empresa" required />
                            </x-form.campo>

                            <div class="flex justify-end gap-3">
                                <x-ui.button type="button" variant="outline" size="sm"
                                    @click="sustituyendo = false">Cancelar</x-ui.button>
                                <x-ui.button type="submit" size="sm">Emitir el reemplazo</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endpuede

        {{-- Anular --}}
        @puede('ventas.anular')
            @if ($venta->puedeAnularse())
                <div x-show="anulando" x-cloak role="dialog" aria-modal="true" aria-labelledby="titulo-modal-anular-venta"
                    class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto overscroll-contain p-5">
                    <div @click="anulando = false" class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px]"></div>

                    <div x-trap.inert.noscroll="anulando"
                        class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto overscroll-contain rounded-3xl bg-white p-6 dark:bg-gray-900 sm:p-8">
                        <h2 id="titulo-modal-anular-venta" class="mb-2 text-xl font-semibold text-gray-800 dark:text-white/90">Anular venta</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            El stock vuelve al inventario y el comprobante queda anulado, conservando su correlativo.
                            La venta no se borra: queda registrada como anulada, con tu nombre y el motivo.
                        </p>

                        <form method="POST" action="{{ route('ventas.anular', $venta) }}" class="space-y-5">
                            @csrf

                            <x-form.campo label="Motivo de la anulación" for="motivo_anulacion" name="motivo_anulacion"
                                required>
                                <x-form.textarea id="motivo_anulacion" name="motivo_anulacion"
                                    placeholder="Error en el cobro, el cliente se arrepintió, producto equivocado…"
                                    required />
                            </x-form.campo>

                            <div class="flex justify-end gap-3">
                                <x-ui.button type="button" variant="outline" size="sm" @click="anulando = false">Cancelar</x-ui.button>
                                <x-ui.button type="submit" variant="danger" size="sm">Anular venta</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endpuede
    </div>
@endsection
