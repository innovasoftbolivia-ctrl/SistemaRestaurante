@extends('layouts.app')

@php
    use App\Support\Config;

    // Qué pasa con el stock en cada final: es lo que hay que entender antes
    // de elegir, así que va al lado de cada opción.
    $explicaciones = [
        'REPUESTO' => 'Lo cambió en el acto: sale lo fallado y entra lo bueno. El stock queda igual.',
        'PENDIENTE' => 'Se lo llevó y traerá el reemplazo: el stock baja hoy y sube cuando llegue.',
        'NOTA_CREDITO' => 'No repone: queda a cuenta con el proveedor. El stock baja.',
    ];

    // Sin opción marcada de entrada: es una decisión que cambia el stock.
    $esperaElegida = old('espera');
@endphp

@section('content')
    <form method="POST" action="{{ route('devoluciones-compra.store', $compra) }}" class="space-y-6">
        @csrf
        @unEnvio

        <x-common.component-card title="De qué compra"
            desc="La devolución sale de esta factura: solo se puede devolver lo que llegó en ella y todavía no se devolvió.">
            <dl class="grid grid-cols-1 gap-4 text-theme-sm sm:grid-cols-3">
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Proveedor</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $compra->proveedor?->razon_social }}</dd>
                </div>
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Compra</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">
                        #{{ $compra->id }}
                        @if ($compra->documento_externo)
                            · {{ $compra->documento_externo }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Fecha</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $compra->fecha?->format('d/m/Y H:i') }}</dd>
                </div>
            </dl>
        </x-common.component-card>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Qué se devuelve</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Escribe la cantidad en unidades de lo que se lleva el proveedor. Lo que quede en blanco no se devuelve.
                </p>
            </div>
            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <x-tabla.th>Producto</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Llegó</x-tabla.th>
                            <x-tabla.th derecha class="hidden sm:table-cell">Ya devuelto</x-tabla.th>
                            <x-tabla.th derecha class="hidden md:table-cell">Costo</x-tabla.th>
                            <x-tabla.th derecha>A devolver</x-tabla.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($lineas as $linea)
                            <tr>
                                <td class="px-5 py-4 text-theme-sm">
                                    <span class="block font-medium text-gray-800 dark:text-white/90">{{ $linea->producto?->nombre }}</span>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $linea->producto?->codigo }} · se pueden devolver hasta {{ Config::cantidad($linea->devolvible) }}
                                    </span>
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ Config::cantidad($linea->cantidad) }}
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm text-gray-500 sm:table-cell dark:text-gray-400">
                                    {{ Config::cantidad($linea->cantidad_devuelta) }}
                                </td>
                                <td class="hidden px-5 py-4 text-right text-theme-sm whitespace-nowrap text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ Config::importe($linea->costo_unitario) }}
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <label for="cantidad-{{ $linea->id }}" class="sr-only">
                                        Cantidad a devolver de {{ $linea->producto?->nombre }}
                                    </label>
                                    <x-form.input type="number" id="cantidad-{{ $linea->id }}"
                                        name="cantidades[{{ $linea->id }}]" :value="old('cantidades.'.$linea->id)"
                                        min="0" max="{{ $linea->devolvible }}" step="any" placeholder="0"
                                        class="ml-auto max-w-28 text-right" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <x-common.component-card title="Por qué y en qué queda">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-form.campo label="Motivo" for="motivo" name="motivo" required>
                    <x-form.select id="motivo" name="motivo" :opciones="$motivos" placeholder="Elige el motivo"
                        required />
                </x-form.campo>

                <x-form.campo label="Documento del proveedor" for="documento_externo" name="documento_externo"
                    help="La nota de devolución o de crédito, si te dio una.">
                    <x-form.input id="documento_externo" name="documento_externo" maxlength="30" />
                </x-form.campo>
            </div>

            <fieldset>
                <legend class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    ¿En qué quedó con el proveedor?<span class="text-error-600 dark:text-error-400">*</span>
                </legend>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    @foreach ($esperas as $valor => $etiqueta)
                        <label
                            class="flex cursor-pointer gap-3 rounded-xl border border-gray-200 p-4 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:border-gray-800 dark:has-[:checked]:border-brand-500 dark:has-[:checked]:bg-brand-500/10">
                            <input type="radio" name="espera" value="{{ $valor }}" @checked($esperaElegida === $valor)
                                class="mt-0.5 h-4 w-4 accent-brand-500" required />
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-white/90">{{ $etiqueta }}</span>
                                <span class="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $explicaciones[$valor] ?? '' }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('espera')
                    <p class="mt-1.5 text-theme-xs text-error-600 dark:text-error-400">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-form.campo label="Observación" for="observacion" name="observacion">
                <x-form.textarea id="observacion" name="observacion" rows="2" maxlength="255"
                    placeholder="Por ejemplo: 3 botellas llegaron rotas en la caja de abajo." />
            </x-form.campo>
        </x-common.component-card>

        <div class="flex flex-wrap justify-end gap-3">
            <x-ui.button variant="outline" :href="route('compras.show', $compra)">Cancelar</x-ui.button>
            <x-ui.button type="submit">Registrar devolución</x-ui.button>
        </div>
    </form>
@endsection
