@extends('layouts.app')

@php
    use App\Support\Config;

    $esEdicion = $producto->exists;
    $tasa = Config::tasaImpuesto();
    $moneda = Config::moneda();

    // Los campos que quedan detrás de «Más datos». Son los que un comerciante
    // no tiene en la cabeza cuando llega mercadería nueva: el código lo propone
    // el sistema, el de barras lo lee el lector, y proveedor, descripción y
    // foto se pueden completar después sin que el producto deje de venderse.
    // Si alguno vuelve con error, la sección se abre sola: un error escondido
    // detrás de un botón plegado es un formulario que no se puede terminar.
    $opcionales = ['codigo', 'codigo_barras', 'proveedor_id', 'descripcion', 'imagen'];
@endphp

@section('content')
    <form method="POST" enctype="multipart/form-data"
        action="{{ $esEdicion ? route('productos.update', $producto) : route('productos.store') }}"
        x-data="{
            tasa: {{ $tasa }},
            compra: Number(@js(old('precio_compra', $producto->precio_compra ?? 0))),
            venta: Number(@js(old('precio_venta', $producto->precio_venta ?? 0))),
            afecto: @js((bool) old('afecto_impuesto', $producto->afecto_impuesto ?? true)),

            unidades: @js($unidadesInfo),
            unidad: Number(@js(old('unidad_medida_id', $producto->unidad_medida_id ?? ''))) || null,

            vieneEnEmpaque: @js((bool) old('viene_en_empaque', $producto->tieneEmpaque())),
            nombreEmpaque: @js(old('nombre_empaque', $producto->nombre_empaque ?? 'Caja')),
            /* Vacío y no 0: con un 0 puesto hay que borrarlo antes de poder
               escribir 24, y esa es justo la fricción que se vino a quitar. */
            contenidoEmpaque: @js((string) old('contenido_empaque', $producto->contenido_empaque ?? '')),

            masDatos: @js($errors->hasAny($opcionales)),

            get unidadCodigo() {
                return this.unidades[this.unidad]?.codigo ?? '';
            },
            get unidadNombre() {
                return this.unidades[this.unidad]?.nombre ?? 'unidad';
            },
            get pasoUnidad() {
                return this.unidades[this.unidad]?.decimal ? 0.001 : 1;
            },
            /* El empaque solo cuenta cuando está completo: sin contenido no hay
               ninguna cuenta que hacer y la pantalla no debe prometerla. */
            get contenido() {
                return Number(this.contenidoEmpaque) || 0;
            },
            get hayEmpaque() {
                return this.vieneEnEmpaque && this.contenido > 1;
            },
            get empaque() {
                return (this.nombreEmpaque || 'empaque').trim().toLowerCase();
            },

            /* El costo se puede escribir por caja, pero todo lo que se calcula
               —margen, ganancia, comparación con el precio de venta— trabaja
               con el de UNA unidad, que es lo que se guarda. */
            compraPor: @js(old('precio_compra_por', 'UNIDAD')),
            get compraPorCaja() {
                return this.compraPor === 'EMPAQUE' && this.hayEmpaque;
            },
            get compraUnidad() {
                return this.compraPorCaja ? this.compra / this.contenido : this.compra;
            },

            get estante() {
                const base = this.afecto ? this.venta * (1 + this.tasa) : this.venta;
                return base.toFixed(2);
            },
            get margen() {
                return (this.venta - this.compraUnidad).toFixed(2);
            },
            get margenPorcentaje() {
                return this.venta > 0 ? ((this.venta - this.compraUnidad) / this.venta * 100).toFixed(1) : '0.0';
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
            <x-common.component-card title="Qué producto es"
                desc="El nombre con el que lo busca quien atiende el mostrador.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Nombre" for="nombre" name="nombre" required>
                        <x-form.input id="nombre" name="nombre" :value="$producto->nombre"
                            placeholder="Arroz extra 1 kg" required autofocus />
                    </x-form.campo>

                    <x-form.campo label="Categoría" for="categoria_id" name="categoria_id" required>
                        <x-form.select id="categoria_id" name="categoria_id" :value="$producto->categoria_id"
                            placeholder="Selecciona una categoría" :opciones="$categorias" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            {{-- El corazón del formulario, y lo que antes no existía: comprar y
                 vender no tienen por qué usar la misma unidad. --}}
            <x-common.component-card title="Cómo se vende y cómo se compra"
                desc="El stock siempre se cuenta en la unidad de venta. El empaque solo sirve para cargar mercadería sin sacar la calculadora.">
                <x-form.campo label="¿En qué unidad lo vendes?" for="unidad_medida_id" name="unidad_medida_id"
                    required help="Es la unidad en la que se despacha en el mostrador y en la que se cuenta el stock.">
                    <x-form.select id="unidad_medida_id" name="unidad_medida_id"
                        :value="$producto->unidad_medida_id" placeholder="Selecciona una unidad"
                        :opciones="$unidades" x-model.number="unidad" required />
                </x-form.campo>

                <div class="space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <x-form.check name="viene_en_empaque" model="vieneEnEmpaque"
                        label="Lo compro en caja, paquete o plancha" />

                    <div x-show="vieneEnEmpaque" x-cloak class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-form.campo label="¿Cómo se llama el empaque?" for="nombre_empaque" name="nombre_empaque">
                            <x-form.input id="nombre_empaque" name="nombre_empaque" list="empaques-usuales"
                                :value="$producto->nombre_empaque ?? 'Caja'" x-model="nombreEmpaque"
                                placeholder="Caja" maxlength="20" />
                            <datalist id="empaques-usuales">
                                @foreach (['Caja', 'Paquete', 'Plancha', 'Bolsa', 'Fardo', 'Saco', 'Docena', 'Blíster'] as $usual)
                                    <option value="{{ $usual }}"></option>
                                @endforeach
                            </datalist>
                        </x-form.campo>

                        <x-form.campo label="¿Cuántas unidades trae?" for="contenido_empaque" name="contenido_empaque">
                            <x-form.input id="contenido_empaque" name="contenido_empaque" type="number" step="1"
                                min="2" :value="$producto->contenido_empaque" x-model.number="contenidoEmpaque"
                                placeholder="24" />
                        </x-form.campo>

                        {{-- La frase completa, para que la regla no haya que
                             deducirla del formulario. --}}
                        <div class="rounded-xl bg-gray-50 p-4 text-theme-sm sm:col-span-2 dark:bg-white/[0.03]">
                            <template x-if="hayEmpaque && unidad">
                                <p class="text-gray-700 dark:text-gray-300">
                                    Compras de a <b><span x-text="empaque"></span> de
                                        <span x-text="contenido"></span>
                                        <span x-text="unidadCodigo"></span></b>
                                    y vendes de a <b><span x-text="unidadNombre"></span></b>.
                                    Al ingresar mercadería escribirás cuántas
                                    <span x-text="empaque"></span>s llegaron y cuántas sueltas,
                                    y el sistema hace la multiplicación.
                                </p>
                            </template>
                            <template x-if="!(hayEmpaque && unidad)">
                                <p class="text-gray-500 dark:text-gray-400">
                                    Completa la unidad de venta y cuántas unidades trae el empaque.
                                </p>
                            </template>
                        </div>
                    </div>

                    <p x-show="!vieneEnEmpaque" x-cloak class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Déjalo sin marcar si el producto se compra y se vende en la misma unidad: el arroz por kilo,
                        el aceite suelto.
                    </p>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Precios"
                :desc="$tasa > 0
                    ? 'Se registran SIN impuesto. El impuesto se agrega al calcular el total de la venta.'
                    : 'El precio de venta es el que paga el cliente: el sistema no le agrega nada encima.'">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Precio de compra" for="precio_compra" name="precio_compra" required
                        help="Sin impuesto. Se guarda siempre por unidad de venta.">
                        {{-- El ancho va en un envoltorio y no en el propio control:
                             los componentes traen `w-full` y una clase de ancho
                             puesta encima pierde por orden de la hoja de estilos. --}}
                        <div class="flex gap-2">
                            <div class="min-w-0 flex-1">
                                <x-form.input id="precio_compra" name="precio_compra" type="number" step="0.01"
                                    min="0" :value="$producto->precio_compra ?? '0.00'" x-model.number="compra"
                                    required />
                            </div>

                            {{-- La factura del proveedor viene por caja. Se
                                 escribe como viene y el sistema divide. --}}
                            <div class="w-32 shrink-0" x-show="hayEmpaque" x-cloak>
                                <x-form.select name="precio_compra_por" x-model="compraPor">
                                    <option value="UNIDAD" x-text="'por ' + unidadNombre"></option>
                                    <option value="EMPAQUE" x-text="'por ' + empaque"></option>
                                </x-form.select>
                            </div>
                        </div>

                        <p x-show="compraPorCaja && compra > 0" x-cloak
                            class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            = {{ $moneda }} <b x-text="compraUnidad.toFixed(2)"></b> por
                            <span x-text="unidadCodigo"></span>, que es lo que se guarda
                        </p>
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

                {{-- Lo que cuesta la caja entera: es la cifra que aparece en la
                     factura del proveedor, y sirve para comprobar de un vistazo
                     que el costo por unidad se escribió bien. --}}
                <div x-show="hayEmpaque && compra > 0 && !compraPorCaja" x-cloak
                    class="rounded-xl border border-gray-200 px-4 py-3 text-theme-sm dark:border-gray-800">
                    <span class="text-gray-500 dark:text-gray-400">Una
                        <span x-text="empaque"></span> de <span x-text="contenido"></span>
                        te cuesta</span>
                    <b class="text-gray-800 dark:text-white/90">{{ $moneda }}
                        <span x-text="(compraUnidad * contenido).toFixed(2)"></span></b>
                    <span class="text-gray-500 dark:text-gray-400">— si no es lo que dice tu factura,
                        cámbialo a «por <span x-text="empaque"></span>»</span>
                </div>

                <template x-if="venta > 0 && compraUnidad > venta">
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
                @if ($esEdicion)
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                        <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock actual</p>
                        <p class="text-lg font-semibold {{ $producto->bajo_minimo ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                            {{ Config::cantidad($producto->stock_actual) }} {{ $producto->unidadMedida?->codigo }}
                        </p>
                        @if ($producto->stock_desglosado)
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $producto->stock_desglosado }}
                            </p>
                        @endif
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
                    {{-- Mismo contador que el ingreso de mercadería, porque es lo
                         mismo: lo que hay físicamente el día que se da de alta.
                         Las expresiones apuntan a los campos de arriba, así que
                         la casilla de cajas aparece en cuanto se dice que el
                         producto viene en caja. --}}
                    <x-form.cantidad-empaque campo="stock_inicial" prefijo="ini_" label="Stock inicial"
                        help="Las unidades contadas físicamente. Queda registrado como carga inicial en el kardex."
                        valor="0" :requerido="false" hay-empaque="hayEmpaque" contenido="contenido"
                        empaque="empaque" unidad="unidadCodigo" paso="pasoUnidad" />
                @endif

                <x-form.campo label="Stock mínimo" for="stock_minimo" name="stock_minimo" required
                    help="Cuando el stock llega a este nivel, el producto aparece en la alerta de reposición.">
                    <x-form.input id="stock_minimo" name="stock_minimo" type="number" step="0.001" min="0"
                        :value="$producto->stock_minimo ?? '0'" required />
                </x-form.campo>
            </x-common.component-card>

            <x-common.component-card title="Disponibilidad">
                <x-form.check name="activo" :checked="$producto->activo ?? true"
                    label="Producto disponible para la venta" />
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Un producto descatalogado deja de ofrecerse en el mostrador, pero sigue siendo legible en las
                    ventas ya emitidas.
                </p>
            </x-common.component-card>

            {{-- Todo lo que se puede completar después. Plegado por defecto:
                 es lo que hacía que dar de alta un producto pareciera un
                 trámite en vez de anotar lo que llegó. --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <button type="button" @click="masDatos = !masDatos"
                    class="flex w-full items-center justify-between px-6 py-5 text-left"
                    :aria-expanded="masDatos ? 'true' : 'false'" aria-controls="mas-datos">
                    <span>
                        <span class="block text-base font-medium text-gray-800 dark:text-white/90">Más datos</span>
                        <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">
                            Código, barras, proveedor y foto. Todo opcional.
                        </span>
                    </span>
                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        class="flex-none text-gray-400 transition" :class="masDatos && 'rotate-180'">
                        <path d="M19 9l-7 7-7-7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                </button>

                <div id="mas-datos" x-show="masDatos" x-cloak
                    class="space-y-6 border-t border-gray-100 p-4 sm:p-6 dark:border-gray-800">
                    <x-form.campo label="Código interno" for="codigo" name="codigo" required
                        :help="$siguienteCodigo ? 'Lo propone el sistema: '.$siguienteCodigo : 'El que se usa en el mostrador.'">
                        <x-form.input id="codigo" name="codigo" :value="$producto->codigo ?? $siguienteCodigo"
                            placeholder="P-0013" required />
                    </x-form.campo>

                    <x-form.campo label="Código de barras" for="codigo_barras" name="codigo_barras"
                        help="Pásale el lector estando en este campo. Solo dígitos.">
                        <x-form.input id="codigo_barras" name="codigo_barras" :value="$producto->codigo_barras"
                            inputmode="numeric" placeholder="7750001000011" />
                    </x-form.campo>

                    <x-form.campo label="Proveedor" for="proveedor_id" name="proveedor_id"
                        help="Quién abastece habitualmente este producto.">
                        <x-form.select id="proveedor_id" name="proveedor_id" :value="$producto->proveedor_id"
                            placeholder="Sin proveedor asignado" :opciones="$proveedores" />
                    </x-form.campo>

                    <x-form.campo label="Descripción" for="descripcion" name="descripcion">
                        <x-form.textarea id="descripcion" name="descripcion" :value="$producto->descripcion"
                            placeholder="Presentación, marca, detalles que ayuden a identificarlo" />
                    </x-form.campo>

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
                    }" class="space-y-3">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-400">Foto</p>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                            Se ve en el mostrador y en el catálogo. JPG, PNG o WEBP, hasta 2 MB.
                        </p>

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
                </div>
            </div>
        </div>
    </form>
@endsection
