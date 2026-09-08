@extends('layouts.app')

@php
    use App\Support\Config;

    $esEdicion = $producto->exists;
    $tasa = Config::tasaImpuesto();
    $moneda = Config::moneda();
@endphp

@section('content')
    <form method="POST" enctype="multipart/form-data"
        action="{{ $esEdicion ? route('productos.update', $producto) : route('productos.store') }}"
        x-data="{
            tasa: {{ $tasa }},
            compra: Number(@js(old('precio_compra', $producto->precio_compra ?? 0))),
            venta: Number(@js(old('precio_venta', $producto->precio_venta ?? 0))),
            afecto: @js((bool) old('afecto_impuesto', $producto->afecto_impuesto ?? true)),

            get estante() {
                const base = this.afecto ? this.venta * (1 + this.tasa) : this.venta;
                return base.toFixed(2);
            },
            get margen() {
                return (this.venta - this.compra).toFixed(2);
            },
            get margenPorcentaje() {
                return this.venta > 0 ? ((this.venta - this.compra) / this.venta * 100).toFixed(1) : '0.0';
            },
            /* Al revés: se escribe el precio de estante deseado y sale la base. */
            desdeEstante(valor) {
                const objetivo = Number(valor);
                if (!objetivo) return;
                this.venta = Number((this.afecto ? objetivo / (1 + this.tasa) : objetivo).toFixed(2));
            }
        }"
        class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        {{-- El código chocó con un producto que ya existe. El error del campo
             lo dice, pero ahí termina: casi siempre lo que se quería era
             sumarle stock a ese mismo producto, así que el atajo va aquí,
             a un clic, en vez de obligar a buscarlo de nuevo a mano. --}}
        @if (session('producto_duplicado'))
            @php($duplicado = session('producto_duplicado'))
            <div class="lg:col-span-3">
                <x-ui.alert variant="warning" title="Ese producto ya está en el catálogo"
                    :message="'«'.$duplicado['nombre'].'» ('.$duplicado['codigo'].') ya usa ese código. Si solo necesitas sumarle stock, no lo cargues de nuevo: hazlo desde el inventario y quedará en el kardex con su motivo y su responsable.'"
                    :showLink="true" :linkHref="route('inventario.index', ['buscar' => $duplicado['codigo']])"
                    linkText="Ir a cargarle stock" />
            </div>
        @endif

        <div class="space-y-6 lg:col-span-2">
            <x-common.component-card title="Identificación"
                desc="El código interno se usa en el mostrador; el de barras, con el lector.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Nombre" for="nombre" name="nombre" required>
                        <x-form.input id="nombre" name="nombre" :value="$producto->nombre"
                            placeholder="Arroz extra 1 kg" required />
                    </x-form.campo>

                    <x-form.campo label="Código interno" for="codigo" name="codigo" required
                        :help="$siguienteCodigo ? 'Sugerido: '.$siguienteCodigo : null">
                        <x-form.input id="codigo" name="codigo" :value="$producto->codigo ?? $siguienteCodigo"
                            placeholder="P-0013" required />
                    </x-form.campo>

                    <x-form.campo label="Código de barras" for="codigo_barras" name="codigo_barras"
                        help="Opcional. Solo dígitos.">
                        <x-form.input id="codigo_barras" name="codigo_barras" :value="$producto->codigo_barras"
                            inputmode="numeric" placeholder="7750001000011" />
                    </x-form.campo>

                    <x-form.campo label="Categoría" for="categoria_id" name="categoria_id" required>
                        <x-form.select id="categoria_id" name="categoria_id" :value="$producto->categoria_id"
                            placeholder="Selecciona una categoría" :opciones="$categorias" required />
                    </x-form.campo>

                    <x-form.campo label="Unidad de medida" for="unidad_medida_id" name="unidad_medida_id" required
                        help="Decide si la cantidad puede llevar decimales.">
                        <x-form.select id="unidad_medida_id" name="unidad_medida_id"
                            :value="$producto->unidad_medida_id" placeholder="Selecciona una unidad"
                            :opciones="$unidades" required />
                    </x-form.campo>

                    <x-form.campo label="Proveedor" for="proveedor_id" name="proveedor_id"
                        help="Opcional. Quién abastece habitualmente este producto.">
                        <x-form.select id="proveedor_id" name="proveedor_id" :value="$producto->proveedor_id"
                            placeholder="Sin proveedor asignado" :opciones="$proveedores" />
                    </x-form.campo>

                    <div class="sm:col-span-2">
                        <x-form.campo label="Descripción" for="descripcion" name="descripcion">
                            <x-form.textarea id="descripcion" name="descripcion" :value="$producto->descripcion"
                                placeholder="Presentación, marca, detalles que ayuden a identificarlo" />
                        </x-form.campo>
                    </div>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Precios"
                :desc="$tasa > 0
                    ? 'Se registran SIN impuesto. El impuesto se agrega al calcular el total de la venta.'
                    : 'El precio de venta es el que paga el cliente: el sistema no le agrega nada encima.'">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Precio de compra" for="precio_compra" name="precio_compra" required
                        help="Lo que cuesta al negocio, sin impuesto.">
                        <x-form.input id="precio_compra" name="precio_compra" type="number" step="0.01" min="0"
                            :value="$producto->precio_compra ?? '0.00'" x-model.number="compra" required />
                    </x-form.campo>

                    <x-form.campo :label="$tasa > 0 ? 'Precio de venta (base)' : 'Precio de venta'"
                        for="precio_venta" name="precio_venta" required
                        :help="$tasa > 0
                            ? 'Base imponible: es lo que se guarda en la base de datos.'
                            : 'Lo que paga el cliente por una unidad.'">
                        <x-form.input id="precio_venta" name="precio_venta" type="number" step="0.01" min="0"
                            :value="$producto->precio_venta ?? '0.00'" x-model.number="venta" required />
                    </x-form.campo>

                    {{-- Con la tasa en 0 el precio de estante y la base son el mismo
                         número, y la casilla de impuesto no decide nada: se muestran
                         solo cuando el negocio trabaja con impuesto. El valor de
                         `afecto_impuesto` viaja igual, para no perderlo al guardar. --}}
                    @if ($tasa > 0)
                        <x-form.campo label="Precio de estante" for="precio_estante"
                            help="Lo que paga el cliente. Escríbelo aquí y la base se calcula sola.">
                            {{-- Se refresca cuando cambian la base o el impuesto, pero no
                                 mientras se escribe en él: si no, el cursor daría saltos. --}}
                            <x-form.input id="precio_estante" type="number" step="0.01" min="0"
                                x-effect="if (document.activeElement !== $el) $el.value = estante"
                                @input="desdeEstante($event.target.value)" />
                        </x-form.campo>

                        <div class="flex flex-col justify-end gap-1.5 pb-1">
                            <x-form.check name="afecto_impuesto" :checked="$producto->afecto_impuesto ?? true"
                                model="afecto"
                                label="Afecto al impuesto ({{ number_format($tasa * 100, 0) }}%)" />
                            <x-ui.en-construccion size="sm" titulo="Tasa provisional" />
                        </div>
                    @else
                        <input type="hidden" name="afecto_impuesto"
                            value="{{ (int) old('afecto_impuesto', $producto->afecto_impuesto ?? true) }}">
                    @endif
                </div>

                {{-- Resumen en vivo, para no tener que sacar la calculadora --}}
                <div
                    class="grid @if ($tasa > 0) grid-cols-3 @else grid-cols-2 @endif gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                    @if ($tasa > 0)
                        <div>
                            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Estante</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ $moneda }} <span x-text="estante"></span>
                            </p>
                        </div>
                    @endif
                    <div>
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ganancia</p>
                        <p class="text-lg font-semibold"
                            :class="margen >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400'">
                            {{ $moneda }} <span x-text="margen"></span>
                        </p>
                    </div>
                    <div>
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Margen</p>
                        <p class="text-lg font-semibold"
                            :class="margen >= 0 ? 'text-success-700 dark:text-success-500' : 'text-error-600 dark:text-error-400'">
                            <span x-text="margenPorcentaje"></span>%
                        </p>
                    </div>
                </div>

                <template x-if="venta > 0 && compra > venta">
                    <x-ui.alert variant="warning" title="El precio de venta está por debajo del costo"
                        message="Tal como está, cada unidad vendida deja pérdida." />
                </template>
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Guardar">
                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit">
                        {{ $esEdicion ? 'Guardar cambios' : 'Registrar producto' }}
                    </x-ui.button>
                    <x-ui.button variant="outline" :href="route('productos.index')">Cancelar</x-ui.button>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Inventario">
                <x-form.campo label="Stock mínimo" for="stock_minimo" name="stock_minimo" required
                    help="Cuando el stock llega a este nivel, el producto aparece en la alerta de reposición.">
                    <x-form.input id="stock_minimo" name="stock_minimo" type="number" step="0.001" min="0"
                        :value="$producto->stock_minimo ?? '0'" required />
                </x-form.campo>

                @if ($esEdicion)
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock actual</p>
                        <p class="text-lg font-semibold {{ $producto->bajo_minimo ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                            {{ Config::cantidad($producto->stock_actual) }} {{ $producto->unidadMedida?->codigo }}
                        </p>
                        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                            El stock no se edita desde aquí: cambia con un ingreso de mercadería o un ajuste, y cada
                            cambio queda en el kardex.
                        </p>
                        <a href="{{ route('productos.show', $producto) }}"
                            class="mt-2 inline-block text-theme-xs text-brand-500 dark:text-brand-400 hover:text-brand-600">
                            Ver ficha y kardex →
                        </a>
                    </div>
                @else
                    <x-form.campo label="Stock inicial" for="stock_inicial" name="stock_inicial"
                        help="Las unidades contadas físicamente. Queda registrado como carga inicial en el kardex.">
                        <x-form.input id="stock_inicial" name="stock_inicial" type="number" step="0.001" min="0"
                            value="0" />
                    </x-form.campo>
                @endif
            </x-common.component-card>

            <x-common.component-card title="Foto"
                desc="Se ve en el mostrador y en el catálogo. JPG, PNG o WEBP, hasta 2 MB.">
                <div x-data="{
                    previa: @js($producto->imagen_url),
                    quitar: false,

                    elegir(evento) {
                        const archivo = evento.target.files[0];
                        if (!archivo) return;

                        this.quitar = false;
                        this.previa = URL.createObjectURL(archivo);
                    },

                    quitarFoto() {
                        this.quitar = true;
                        this.previa = null;
                        this.$refs.archivo.value = '';
                    }
                }" class="space-y-4">
                    <input type="hidden" name="quitar_imagen" :value="quitar ? '1' : '0'" />

                    <div class="flex items-start gap-4">
                        {{-- La vista previa muestra la foto tal cual entrará al
                             catálogo: entera y sin recortar. --}}
                        <template x-if="previa">
                            <span class="flex h-28 w-28 flex-none items-center justify-center overflow-hidden rounded-2xl border border-gray-200 bg-white p-2 dark:border-gray-700">
                                <img :src="previa" alt="Vista previa"
                                    class="max-h-full max-w-full object-scale-down" />
                            </span>
                        </template>

                        <template x-if="!previa">
                            <span class="flex h-28 w-28 flex-none items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-gray-50 text-gray-300 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-600">
                                <svg aria-hidden="true" class="h-10 w-10" viewBox="0 0 24 24" fill="none">
                                    <path d="M3.75 7.25 12 3.5l8.25 3.75-8.25 3.75L3.75 7.25Z" stroke="currentColor"
                                        stroke-width="1.5" stroke-linejoin="round" />
                                    <path d="M3.75 12 12 15.75 20.25 12M3.75 16.75 12 20.5l8.25-3.75"
                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                        stroke-linejoin="round" />
                                </svg>
                            </span>
                        </template>

                        <div class="flex-1 space-y-2">
                            <input type="file" name="imagen" x-ref="archivo" @change="elegir($event)"
                                accept="image/jpeg,image/png,image/webp"
                                class="w-full text-theme-xs text-gray-500 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-2 file:text-theme-xs file:font-medium file:text-white hover:file:bg-brand-600 dark:text-gray-400" />

                            <button type="button" x-show="previa" x-cloak @click="quitarFoto()"
                                class="text-theme-xs text-error-600 dark:text-error-400 hover:text-error-600">
                                Quitar la foto
                            </button>

                            @error('imagen')
                                <p class="text-theme-xs text-error-600 dark:text-error-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Disponibilidad">
                <x-form.check name="activo" :checked="$producto->activo ?? true"
                    label="Producto disponible para la venta" />
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Un producto descatalogado deja de ofrecerse en el mostrador, pero sigue siendo legible en las
                    ventas ya emitidas.
                </p>
            </x-common.component-card>
        </div>
    </form>
@endsection
