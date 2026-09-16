@extends('layouts.app')

@php
    $f = fn ($valor) => number_format((float) $valor, 2, '.', ',');
    $t = $libro['totales'];
    $diferencia = round($t['debito_fiscal'] - $t['impuesto_ticket'], 2);
@endphp

@section('content')
    <div class="space-y-6">
        <x-ui.en-construccion titulo="Borrador para el contador">
            El sistema todavía no factura con el SIN: las facturas no tienen código de autorización ni código de control,
            y este libro no es el que se presenta. Sirve para que el contador no arme el mes a mano. Confirma con él el
            formato vigente antes de usarlo.
        </x-ui.en-construccion>

        @if ($libro['sin_impuesto_configurado'])
            {{-- Sin IVA configurado, el ticket no cobró impuesto: el libro no puede
                 declarar un débito fiscal que nadie cobró. --}}
            <x-ui.alert variant="warning" title="El negocio está configurado sin IVA"
                message="La tasa de impuesto está en 0 %, así que las facturas del mes salen como exentas y el débito fiscal es cero. Si el negocio ya factura con IVA, actívalo en Sistema → Configuración → Impuesto y precios." />
        @endif

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('reportes.libro-ventas') }}"
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-end gap-3">
                    <x-form.campo label="Mes" for="mes" class="w-48">
                        <x-form.input id="mes" name="mes" type="month" :value="$mes" />
                    </x-form.campo>
                    <x-ui.button type="submit" size="sm">Ver</x-ui.button>
                </div>
                <div class="flex gap-2">
                    <x-ui.button variant="outline" size="sm" :href="route('reportes.libro-ventas.excel', ['mes' => $mes])">Excel</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('reportes.libro-ventas.pdf', ['mes' => $mes])">PDF</x-ui.button>
                </div>
            </form>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                'Facturas válidas' => collect($libro['filas'])->where('estado', 'V')->count(),
                'Importe facturado' => $moneda.' '.$f($t['importe_total']),
                'Base para débito fiscal' => $moneda.' '.$f($t['base_debito']),
                'Débito fiscal (13 %)' => $moneda.' '.$f($t['debito_fiscal']),
            ] as $etiqueta => $valor)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{{ $valor }}</p>
                </div>
            @endforeach
        </div>

        @if ($t['debito_fiscal'] > 0 && abs($diferencia) >= 0.01)
            {{-- La diferencia a la vista, con números del propio mes: es lo que
                 hace falta para decidir cómo calcular el impuesto en todo el sistema. --}}
            <x-ui.alert variant="warning" title="El impuesto del ticket no es el débito fiscal"
                :message="'Este mes el débito fiscal, 13 % de lo cobrado como se calcula en Bolivia, es '.$moneda.' '.$f($t['debito_fiscal'])
                    .'. Los tickets suman '.$moneda.' '.$f($t['impuesto_ticket']).' de impuesto, porque el sistema lo calcula sumando la tasa a una base sin impuesto. '
                    .'La diferencia es de '.$moneda.' '.$f($diferencia).'. Hay que decidir con el contador cómo se calcula en todo el sistema.'" />
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto overscroll-x-contain">
                <table class="min-w-full whitespace-nowrap text-theme-xs">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3 text-right font-medium">N°</th>
                            <th class="px-3 py-3 text-left font-medium">Fecha</th>
                            <th class="px-3 py-3 text-left font-medium">Factura</th>
                            <th class="px-3 py-3 text-left font-medium">NIT / CI</th>
                            <th class="px-3 py-3 text-left font-medium">Nombre o razón social</th>
                            <th class="px-3 py-3 text-right font-medium" title="Lo cobrado">Importe (A)</th>
                            <th class="px-3 py-3 text-right font-medium" title="Productos sin impuesto">Exentas (C)</th>
                            <th class="px-3 py-3 text-right font-medium">Base (G)</th>
                            <th class="px-3 py-3 text-right font-medium">Débito 13 % (H)</th>
                            <th class="px-3 py-3 text-center font-medium">Estado</th>
                            <th class="px-3 py-3 text-right font-medium" title="Solo referencia">Imp. del ticket</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-mono text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                        @forelse ($libro['filas'] as $fila)
                            <tr @class(['text-gray-400 dark:text-gray-500' => $fila['estado'] === 'A'])>
                                <td class="px-3 py-2.5 text-right">{{ $fila['numero'] }}</td>
                                <td class="px-3 py-2.5">{{ $fila['fecha']->format('d/m/Y') }}</td>
                                <td class="px-3 py-2.5">{{ $fila['factura'] }}</td>
                                <td class="px-3 py-2.5">{{ $fila['documento'] ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-sans">{{ $fila['cliente'] }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $f($fila['importe_total']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $f($fila['exentas']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $f($fila['base_debito']) }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold">{{ $f($fila['debito_fiscal']) }}</td>
                                <td class="px-3 py-2.5 text-center" title="{{ $fila['estado'] === 'V' ? 'Válida' : 'Anulada' }}">{{ $fila['estado'] }}</td>
                                <td class="px-3 py-2.5 text-right text-gray-400">{{ $f($fila['impuesto_ticket']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-5 py-10 text-center font-sans text-theme-sm text-gray-500 dark:text-gray-400">
                                    No se emitieron facturas en el mes.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($libro['filas'])
                        <tfoot class="border-t border-gray-200 font-mono font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">
                            <tr>
                                <td class="px-3 py-3 font-sans" colspan="5">Total del mes</td>
                                <td class="px-3 py-3 text-right">{{ $f($t['importe_total']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $f($t['exentas']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $f($t['base_debito']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $f($t['debito_fiscal']) }}</td>
                                <td></td>
                                <td class="px-3 py-3 text-right text-gray-400">{{ $f($t['impuesto_ticket']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <p class="max-w-4xl text-theme-xs text-gray-500 dark:text-gray-400">
            Solo facturas: los recibos no van en el libro. Las anuladas y sustituidas figuran con estado A e importes en
            cero. El importe ya viene con el descuento aplicado, por eso la columna de descuentos va en cero. Las
            devoluciones no restan aquí: corresponden a notas de crédito. El Excel y el PDF traen además las columnas
            de ICE y tasas, tasa cero, subtotal, descuentos y los códigos, vacías mientras no haya facturación del SIN.
        </p>
    </div>
@endsection
