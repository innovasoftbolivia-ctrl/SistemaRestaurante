@extends('layouts.app')

@section('content')
    {{-- Los datos del negocio se ven en la vista previa mientras se escriben: son
         los que salen en el encabezado de cada comprobante, y es más fácil
         encontrar una errata en el papel que en un formulario. --}}
    <form method="POST" action="{{ route('configuracion.update') }}"
        x-data="{
            nombre: @js(old('negocio_nombre', $actual['negocio_nombre'])),
            nit: @js(old('negocio_documento', $actual['negocio_documento'])),
            direccion: @js(old('negocio_direccion', $actual['negocio_direccion'])),
            telefono: @js(old('negocio_telefono', $actual['negocio_telefono'])),
        }"
        class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @method('PUT')

        <div class="space-y-6 lg:col-span-2">

            <x-common.component-card title="El negocio"
                desc="Salen en el encabezado de cada ticket, recibo y factura.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Nombre del negocio" for="negocio_nombre" name="negocio_nombre" required
                        class="sm:col-span-2">
                        <x-form.input id="negocio_nombre" name="negocio_nombre" :value="$actual['negocio_nombre']"
                            x-model="nombre" maxlength="120" required />
                    </x-form.campo>

                    <x-form.campo label="NIT" for="negocio_documento" name="negocio_documento" required
                        help="Solo dígitos, sin guiones.">
                        <x-form.input id="negocio_documento" name="negocio_documento"
                            :value="$actual['negocio_documento']" x-model="nit" inputmode="numeric"
                            maxlength="20" required />
                    </x-form.campo>

                    <x-form.campo label="Teléfono" for="negocio_telefono" name="negocio_telefono">
                        <x-form.input id="negocio_telefono" name="negocio_telefono"
                            :value="$actual['negocio_telefono']" x-model="telefono" inputmode="tel" maxlength="30" />
                    </x-form.campo>

                    <x-form.campo label="Dirección" for="negocio_direccion" name="negocio_direccion"
                        class="sm:col-span-2">
                        <x-form.input id="negocio_direccion" name="negocio_direccion"
                            :value="$actual['negocio_direccion']" x-model="direccion" maxlength="200" />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Moneda e impuesto"
                desc="Afectan solo a las ventas nuevas. Cada venta ya registrada conserva la tasa y la moneda con las que se hizo.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Moneda" for="moneda_codigo" name="moneda_codigo" required>
                        <x-form.select id="moneda_codigo" name="moneda_codigo" :opciones="$monedas"
                            :value="$actual['moneda_codigo']" required />
                    </x-form.campo>

                    <x-form.campo label="Tasa de impuesto (%)" for="tasa_impuesto" name="tasa_impuesto" required
                        help="El IVA en Bolivia es del 13 %. Pon 0 si el negocio no desglosa impuesto.">
                        <x-form.input id="tasa_impuesto" name="tasa_impuesto" type="number" step="0.01" min="0"
                            max="100" :value="$actual['tasa_impuesto']" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Mostrador"
                desc="Lo que el cajero puede hacer sin pedir autorización, y lo que se imprime cuando la venta no tiene cliente.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Descuento máximo del cajero (%)" for="descuento_max_cajero"
                        name="descuento_max_cajero" required
                        help="Por encima de esto, el descuento lo tiene que registrar un administrador.">
                        <x-form.input id="descuento_max_cajero" name="descuento_max_cajero" type="number" step="1"
                            min="0" max="100" inputmode="numeric" :value="$actual['descuento_max_cajero']" required />
                    </x-form.campo>

                    <x-form.campo label="Días para sustituir un comprobante" for="dias_max_sustitucion"
                        name="dias_max_sustitucion" required
                        help="Plazo para cambiar un recibo por una factura después de la venta.">
                        <x-form.input id="dias_max_sustitucion" name="dias_max_sustitucion" type="number" step="1"
                            min="0" max="30" inputmode="numeric" :value="$actual['dias_max_sustitucion']" required />
                    </x-form.campo>

                    <x-form.campo label="Nombre del cliente sin registrar" for="cliente_generico_nombre"
                        name="cliente_generico_nombre" required class="sm:col-span-2"
                        help="Lo que dice el comprobante cuando se vende sin elegir un cliente.">
                        <x-form.input id="cliente_generico_nombre" name="cliente_generico_nombre"
                            :value="$actual['cliente_generico_nombre']" maxlength="60" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Comprobantes"
                desc="La serie con la que se numera cada tipo de documento. La factura se emite cuando el cliente es una empresa; el recibo, cuando es una persona.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Serie de facturas" for="serie_factura" name="serie_factura" required>
                        <x-form.select id="serie_factura" name="serie_factura" :opciones="$series['FAC']"
                            :value="$actual['serie_factura']" placeholder="Elige una serie" required />
                    </x-form.campo>

                    <x-form.campo label="Serie de recibos" for="serie_recibo" name="serie_recibo" required>
                        <x-form.select id="serie_recibo" name="serie_recibo" :opciones="$series['REC']"
                            :value="$actual['serie_recibo']" placeholder="Elige una serie" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    @if ($modificado)
                        Última modificación: {{ \Illuminate\Support\Carbon::parse($modificado)->format('d/m/Y H:i') }}.
                    @endif
                    Cada cambio queda en la bitácora, con el valor anterior.
                </p>
                <x-ui.button type="submit">Guardar cambios</x-ui.button>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:sticky lg:top-24">
                <h2 class="mb-1 text-base font-medium text-gray-800 dark:text-white/90">Así sale en el comprobante</h2>
                <p class="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">El encabezado del ticket, con lo que estás escribiendo.</p>

                <div class="mx-auto max-w-[80mm] rounded-lg border border-dashed border-gray-300 bg-white px-4 py-5 text-center font-mono text-theme-xs leading-relaxed text-gray-800 dark:border-gray-700">
                    <div class="text-sm font-bold" x-text="nombre || 'Nombre del negocio'"></div>
                    <div class="text-gray-500" x-show="nit" x-text="'NIT ' + nit"></div>
                    <div class="text-gray-500" x-show="direccion" x-text="direccion"></div>
                    <div class="text-gray-500" x-show="telefono" x-text="'Tel. ' + telefono"></div>
                    <div class="my-3 border-t border-dashed border-gray-300"></div>
                    <div class="font-bold">RECIBO</div>
                    <div class="text-gray-500">R001-000123</div>
                </div>
            </div>
        </div>
    </form>
@endsection
