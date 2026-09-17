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
            cobra: @js((bool) old('cobra_impuesto', $actual['cobra_impuesto'] === '1')),
            tasa: Number(@js(old('tasa_impuesto', $actual['tasa_impuesto']))),
            incluido: @js(old('precios_incluyen_impuesto', $actual['precios_incluyen_impuesto'])),
            incluidoAntes: @js($actual['precios_incluyen_impuesto']),
            convertir: @js((bool) old('convertir_precios', true)),
            ejemplo: 10,
            /* Lo que ve el mostrador con un producto de ejemplo, en centavos
               enteros y con la misma cuenta que la base. */
            get tasaEfectiva() { return this.cobra ? Math.max(Number(this.tasa) || 0, 0) / 100 : 0; },
            get ivaEjemplo() {
                const c = Math.round(this.ejemplo * 100), t = Math.round(this.tasaEfectiva * 10000), d = 10000 + t;
                return this.incluido === '1'
                    ? Math.floor((2 * c * t + d) / (2 * d)) / 100
                    : Math.floor((c * t + 5000) / 10000) / 100;
            },
            get pagaEjemplo() {
                return this.incluido === '1' ? this.ejemplo : Math.round((this.ejemplo + this.ivaEjemplo) * 100) / 100;
            },
            get cambiaModo() { return this.incluido !== this.incluidoAntes; },
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

            <x-common.component-card title="Moneda">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Moneda" for="moneda_codigo" name="moneda_codigo" required>
                        <x-form.select id="moneda_codigo" name="moneda_codigo" :opciones="$monedas"
                            :value="$actual['moneda_codigo']" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            {{-- Impuesto y precios: lo decide el negocio (y su contador). Afecta solo
                 a las ventas nuevas: cada venta guarda la tasa y el modo con que se hizo.
                 Sin facturación a la vista, el apartado no se muestra y sus valores
                 viajan tal cual en campos ocultos. --}}
            @facturacion
            <x-common.component-card title="Impuesto y precios"
                desc="Si el negocio cobra IVA y cómo van tus precios. Cambiarlo afecta solo a las ventas nuevas: las ya registradas conservan la tasa y el modo con que se hicieron.">
                <div class="space-y-5" data-impuesto-y-precios>
                    <div>
                        <x-form.check name="cobra_impuesto" model="cobra"
                            label="El negocio cobra IVA en sus ventas" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Desmárcalo si el negocio todavía no factura con impuesto: los tickets no mostrarán IVA.
                        </p>
                    </div>

                    <div x-show="cobra" x-cloak class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="Tasa del IVA (%)" for="tasa_impuesto" name="tasa_impuesto"
                            help="En Bolivia es del 13 %.">
                            <x-form.input id="tasa_impuesto" name="tasa_impuesto" type="number" step="0.01" min="0"
                                max="100" :value="$actual['tasa_impuesto']" x-model.number="tasa" x-bind:required="cobra" />
                        </x-form.campo>
                    </div>

                    <fieldset class="space-y-3">
                        <legend class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-400">Tus precios de venta</legend>

                        <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition"
                            :class="incluido === '1' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-gray-200 dark:border-gray-800'">
                            <input type="radio" name="precios_incluyen_impuesto" value="1" x-model="incluido" class="mt-1 accent-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-white/90">Ya incluyen el IVA</span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                    El precio del producto es lo que paga el cliente. El IVA se separa por dentro para la factura.
                                    Es lo habitual en Bolivia.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition"
                            :class="incluido === '0' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-gray-200 dark:border-gray-800'">
                            <input type="radio" name="precios_incluyen_impuesto" value="0" x-model="incluido" class="mt-1 accent-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-white/90">No incluyen el IVA: se suma al cobrar</span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                    El precio del producto es la base y el mostrador le agrega el impuesto.
                                </span>
                            </span>
                        </label>
                    </fieldset>

                    {{-- Ejemplo en vivo: lo que el cajero cobraría con esta configuración. --}}
                    <div class="rounded-xl bg-gray-50 px-4 py-3 text-theme-sm dark:bg-white/[0.03]" data-ejemplo-precio>
                        Un producto con precio {{ App\Support\Config::moneda() }} <b x-text="ejemplo.toFixed(2)"></b>:
                        el cliente paga <b class="text-gray-800 dark:text-white/90">{{ App\Support\Config::moneda() }} <span x-text="pagaEjemplo.toFixed(2)"></span></b><span x-show="tasaEfectiva > 0">,
                        de los que {{ App\Support\Config::moneda() }} <span x-text="ivaEjemplo.toFixed(2)"></span> son IVA</span>.
                    </div>

                    {{-- Cambiar de modo sin convertir le cambiaría el precio a todo el mostrador. --}}
                    <div x-show="cambiaModo" x-cloak class="rounded-xl bg-warning-50 px-4 py-3 dark:bg-orange-500/10">
                        <x-form.check name="convertir_precios" model="convertir"
                            label="Ajustar los precios del catálogo para que el cliente siga pagando lo mismo" />
                        <p class="mt-1.5 text-theme-xs text-warning-700 dark:text-orange-400">
                            <span x-show="incluido === '1'">Cada precio pasa a ser el que hoy paga el cliente, con el IVA ya adentro.</span>
                            <span x-show="incluido === '0'">Cada precio pasa a ser la base sin IVA; el mostrador le suma el impuesto al cobrar.</span>
                            Solo los productos afectos al impuesto. Sin ajustar, todos los precios del mostrador cambian.
                        </p>
                    </div>

                    <p x-show="!cambiaModo && incluido === '0' && cobra" x-cloak class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Con el IVA sumado al cobrar, cambiar la tasa cambia lo que paga el cliente.
                    </p>
                </div>
            </x-common.component-card>
            @else
                <input type="hidden" name="cobra_impuesto" value="{{ $actual['cobra_impuesto'] }}">
                <input type="hidden" name="tasa_impuesto" value="{{ $actual['tasa_impuesto'] }}">
                <input type="hidden" name="precios_incluyen_impuesto" value="{{ $actual['precios_incluyen_impuesto'] }}">
                <input type="hidden" name="serie_factura" value="{{ $actual['serie_factura'] }}">
                <input type="hidden" name="serie_recibo" value="{{ $actual['serie_recibo'] }}">
                <input type="hidden" name="dias_max_sustitucion" value="{{ $actual['dias_max_sustitucion'] }}">
            @endfacturacion

            <x-common.component-card title="Mostrador"
                desc="Lo que el cajero puede hacer sin pedir autorización, y lo que se imprime cuando la venta no tiene cliente.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Descuento máximo del cajero (%)" for="descuento_max_cajero"
                        name="descuento_max_cajero" required
                        help="Por encima de esto, el descuento lo tiene que registrar un administrador.">
                        <x-form.input id="descuento_max_cajero" name="descuento_max_cajero" type="number" step="1"
                            min="0" max="100" inputmode="numeric" :value="$actual['descuento_max_cajero']" required />
                    </x-form.campo>

                    <x-form.campo label="Egreso máximo del cajero (Bs)" for="egreso_max_cajero"
                        name="egreso_max_cajero" required
                        help="Un gasto pagado del cajón por encima de esto lo registra un administrador en el turno del cajero.">
                        <x-form.input id="egreso_max_cajero" name="egreso_max_cajero" type="number" step="0.01"
                            min="0" inputmode="decimal" :value="$actual['egreso_max_cajero']" required />
                    </x-form.campo>

                    @facturacion
                    <x-form.campo label="Días para sustituir un comprobante" for="dias_max_sustitucion"
                        name="dias_max_sustitucion" required
                        help="Plazo para cambiar un recibo por una factura después de la venta.">
                        <x-form.input id="dias_max_sustitucion" name="dias_max_sustitucion" type="number" step="1"
                            min="0" max="30" inputmode="numeric" :value="$actual['dias_max_sustitucion']" required />
                    </x-form.campo>
                    @endfacturacion

                    <x-form.campo label="Días para aceptar una devolución" for="dias_max_devolucion"
                        name="dias_max_devolucion" required
                        help="Pasado este plazo desde la venta, el sistema ya no registra devoluciones. 0 = solo el mismo día.">
                        <x-form.input id="dias_max_devolucion" name="dias_max_devolucion" type="number" step="1"
                            min="0" max="365" inputmode="numeric" :value="$actual['dias_max_devolucion']" required />
                    </x-form.campo>

                    <div class="sm:col-span-2">
                        <x-form.check name="exigir_referencia_pago" :checked="$actual['exigir_referencia_pago'] === '1'"
                            label="Pedir el número de operación en pagos con tarjeta, billetera o transferencia" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            El del voucher o comprobante: sin él no se puede conciliar lo cobrado con el extracto del banco.
                        </p>
                    </div>

                    <x-form.campo label="Nombre del cliente sin registrar" for="cliente_generico_nombre"
                        name="cliente_generico_nombre" required class="sm:col-span-2"
                        help="Lo que dice el comprobante cuando se vende sin elegir un cliente.">
                        <x-form.input id="cliente_generico_nombre" name="cliente_generico_nombre"
                            :value="$actual['cliente_generico_nombre']" maxlength="60" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            @facturacion
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
            @endfacturacion

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
