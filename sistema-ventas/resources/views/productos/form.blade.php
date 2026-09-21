@extends('layouts.app')

@php
    use App\Support\Config;

    $esEdicion = $producto->exists;
    $tasa = Config::tasaImpuesto();
    $incluido = Config::preciosIncluyenImpuesto();
    // Dos precios distintos (base y estante) solo con el impuesto sumado encima.
    $separa = $tasa > 0 && ! $incluido;
    $moneda = Config::moneda();

    // Los campos que quedan detrás de «Más datos». Son los que no se tienen en
    // la cabeza al dar de alta un plato: el código lo propone el sistema, y
    // descripción y foto se pueden completar después sin que deje de venderse.
    // Si alguno vuelve con error, la sección se abre sola: un error escondido
    // detrás de un botón plegado es un formulario que no se puede terminar.
    $opcionales = ['codigo', 'descripcion', 'imagen'];
@endphp

@section('content')
    <form method="POST" enctype="multipart/form-data"
        action="{{ $esEdicion ? route('productos.update', $producto) : route('productos.store') }}"
        x-data="{
            tasa: {{ $tasa }},
            incluido: @js($incluido),
            venta: Number(@js(old('precio_venta', $producto->precio_venta ?? 0))),
            afecto: @js((bool) old('afecto_impuesto', $producto->afecto_impuesto ?? true)),


            masDatos: @js($errors->hasAny($opcionales)),

            get estante() {
                const base = this.afecto ? this.venta * (1 + this.tasa) : this.venta;
                return base.toFixed(2);
            },
            /* El IVA que lleva adentro el precio, igual que la base de datos. */
            get ivaDentro() {
                if (!this.incluido || !this.afecto) return 0;
                const c = Math.round(this.venta * 100), t = Math.round(this.tasa * 10000), d = 10000 + t;
                return Math.floor((2 * c * t + d) / (2 * d)) / 100;
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

        <div class="space-y-6 lg:col-span-2">
            <x-common.component-card title="Qué se ofrece"
                desc="El nombre con el que lo busca quien atiende el mostrador.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo label="Nombre" for="nombre" name="nombre" required>
                        <x-form.input id="nombre" name="nombre" :value="$producto->nombre"
                            placeholder="Pique macho para dos" required autofocus />
                    </x-form.campo>

                    <x-form.campo label="Categoría" for="categoria_id" name="categoria_id" required>
                        <x-form.select id="categoria_id" name="categoria_id" :value="$producto->categoria_id"
                            placeholder="Selecciona una categoría" :opciones="$categorias" required />
                    </x-form.campo>
                </div>
            </x-common.component-card>

            <x-common.component-card title="Precios"
                :desc="$separa
                    ? 'Se registran SIN impuesto. El impuesto se agrega al calcular el total de la venta.'
                    : ($tasa > 0
                        ? 'El precio de venta es el que paga el cliente e incluye el IVA: el sistema lo separa por dentro.'
                        : 'El precio de venta es el que paga el cliente: el sistema no le agrega nada encima.')">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.campo :label="$separa ? 'Precio de venta (base)' : 'Precio de venta'"
                        for="precio_venta" name="precio_venta" required
                        :help="$separa
                            ? 'Base imponible: es lo que se guarda en la base de datos.'
                            : ($tasa > 0 ? 'Lo que paga el cliente por una porción, con el IVA incluido.' : 'Lo que paga el cliente por una porción.')">
                        <x-form.input id="precio_venta" name="precio_venta" type="number" step="0.01" min="0"
                            :value="$producto->precio_venta ?? '0.00'" x-model.number="venta" required />
                    </x-form.campo>

                    {{-- Con la tasa en 0 el precio de estante y la base son el mismo
                         número, y la casilla de impuesto no decide nada: se muestran
                         solo cuando el negocio trabaja con impuesto. El valor de
                         `afecto_impuesto` viaja igual, para no perderlo al guardar. --}}
                    @if ($separa)
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
                    @elseif ($tasa > 0)
                        <div class="flex flex-col justify-end gap-1.5 pb-1">
                            <x-form.check name="afecto_impuesto" :checked="$producto->afecto_impuesto ?? true"
                                model="afecto"
                                label="Lleva IVA ({{ rtrim(rtrim(number_format($tasa * 100, 2), '0'), '.') }}%)" />
                        </div>
                    @else
                        <input type="hidden" name="afecto_impuesto"
                            value="{{ (int) old('afecto_impuesto', $producto->afecto_impuesto ?? true) }}">
                    @endif
                </div>

                {{-- Resumen en vivo, para no tener que sacar la calculadora. Sin
                     impuesto no hay nada que resumir: el precio escrito es el
                     único, y repetirlo en un recuadro no aporta nada. --}}
                @if ($tasa > 0)
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                        @if ($separa)
                            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Estante</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ $moneda }} <span x-text="estante"></span>
                            </p>
                        @else
                            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">IVA incluido</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ $moneda }} <span x-text="ivaDentro.toFixed(2)"></span>
                            </p>
                        @endif
                    </div>
                @endif
            </x-common.component-card>
        </div>

        <div class="space-y-6">
            <x-common.component-card title="Guardar">
                <div class="flex flex-col gap-3">
                    <x-ui.button type="submit">
                        {{ $esEdicion ? 'Guardar cambios' : 'Agregar al menú' }}
                    </x-ui.button>
                    <x-ui.button variant="outline" :href="route('productos.index')">Cancelar</x-ui.button>
                </div>
            </x-common.component-card>

            @puede('registros.eliminar')
            <x-common.component-card title="Disponibilidad">
                <x-form.check name="activo" :checked="$producto->activo ?? true"
                    label="Disponible en el menú" />
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Lo que sale del menú deja de ofrecerse en el mostrador, pero sigue siendo legible en las
                    ventas ya emitidas.
                </p>
            </x-common.component-card>
            @endpuede

            {{-- Todo lo que se puede completar después. Plegado por defecto:
                 es lo que hacía que sumar un plato al menú pareciera un
                 trámite en vez de anotar lo que se cocina. --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <button type="button" @click="masDatos = !masDatos"
                    class="flex w-full items-center justify-between px-6 py-5 text-left"
                    :aria-expanded="masDatos ? 'true' : 'false'" aria-controls="mas-datos">
                    <span>
                        <span class="block text-base font-medium text-gray-800 dark:text-white/90">Más datos</span>
                        <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">
                            Código, descripción y foto. Todo opcional.
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
                            Se ve en el mostrador y en el menú. JPG, PNG o WEBP, hasta 2 MB.
                        </p>

                        <input type="hidden" name="quitar_imagen" :value="quitar ? '1' : '0'" />

                        <div class="flex items-start gap-4">
                            {{-- La vista previa muestra la foto tal cual entrará al
                                 menú: entera y sin recortar. --}}
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
