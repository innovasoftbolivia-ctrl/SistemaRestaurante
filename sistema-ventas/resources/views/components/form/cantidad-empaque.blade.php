@props([
    // Nombre del campo cuando el producto NO viene en empaque: ahí sigue
    // habiendo una sola casilla con el total, como siempre.
    'campo' => 'cantidad',
    'label' => 'Cantidad que ingresa',
    'help' => null,
    'valor' => null,
    'prefijo' => '',
    // El stock inicial admite el cero —se da de alta el producto hoy y la
    // mercadería llega mañana—; un ingreso de mercadería, no.
    'requerido' => true,

    // Todo lo que decide la aritmética llega como EXPRESIÓN de Alpine, no como
    // valor de PHP. Es lo que permite usar el mismo componente en la ficha de
    // un producto ya guardado (`true`, `24`), en la fila del almacén
    // (`sel.contenido`, que cambia con cada producto elegido) y en el alta,
    // donde el contenido de la caja se está escribiendo en ese mismo momento.
    'hayEmpaque' => 'false',
    'contenido' => '0',
    // En singular, como toda etiqueta de formulario («Caja» sobre la casilla
    // donde se escribe 3). El plural solo hace falta en la prosa que redacta
    // el servidor —el kardex, el desglose del stock— y se arma allí.
    'empaque' => "'empaque'",
    'unidad' => "''",
    // Paso del campo de sueltas: 1 para unidades, 0.001 para kilos y litros.
    'paso' => '1',
])

@php
    $etiqueta = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $nota = 'mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400';

    // Las tres casillas apuntan al mismo error: da igual cuál se haya usado,
    // el problema es siempre «cuánto entra».
    $error = collect([$campo, 'empaques', 'sueltas'])
        ->map(fn (string $c) => $errors->first($c))
        ->filter()
        ->first();
@endphp

{{-- `empaques` y `sueltas` arrancan vacíos y no en cero, para no obligar a
     borrar un 0 antes de escribir. La cuenta los lee como cero igual. --}}
<div x-data="{
    empaques: @js(old('empaques')),
    sueltas: @js(old('sueltas')),
    get total() {
        const dentro = (Number(this.empaques) || 0) * ({{ $contenido }});
        return Math.round((dentro + (Number(this.sueltas) || 0)) * 1000) / 1000;
    },
}" {{ $attributes->merge(['class' => 'space-y-3']) }}>

    {{-- Producto sin empaque: una sola casilla, igual que siempre.

         Los campos de la forma que no se está usando van deshabilitados, y un
         campo deshabilitado no se envía: así el servidor recibe solo la forma
         con la que se contó de verdad y no tiene que adivinar cuál mandaba. --}}
    <div x-show="!({{ $hayEmpaque }})" x-cloak>
        <label for="{{ $prefijo }}cantidad" class="{{ $etiqueta }}">
            {{ $label }}@if ($requerido)<span class="text-error-600 dark:text-error-400">*</span>@endif
        </label>
        <x-form.input :id="$prefijo.'cantidad'" :name="$campo" type="number" min="0" :value="$valor"
            x-bind:step="{{ $paso }}" x-bind:disabled="{{ $hayEmpaque }}" />
        @if ($help)
            <p class="{{ $nota }}">{{ $help }}</p>
        @endif
    </div>

    {{-- Producto que viene en caja: se cuenta como se cuenta en el depósito
         —las cajas enteras por un lado, lo que vino suelto por el otro— y la
         multiplicación la hace el sistema, a la vista. --}}
    <div x-show="{{ $hayEmpaque }}" x-cloak class="space-y-3">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="{{ $prefijo }}empaques" class="{{ $etiqueta }} capitalize"
                    x-text="{{ $empaque }}"></label>
                <x-form.input :id="$prefijo.'empaques'" name="empaques" type="number" step="1" min="0"
                    placeholder="0" x-model="empaques" x-bind:disabled="!({{ $hayEmpaque }})" />
                <p class="{{ $nota }}">
                    cada <span x-text="{{ $empaque }}"></span> trae
                    <span x-text="{{ $contenido }}"></span> <span x-text="{{ $unidad }}"></span>
                </p>
            </div>

            <div>
                <label for="{{ $prefijo }}sueltas" class="{{ $etiqueta }}">Sueltas</label>
                <x-form.input :id="$prefijo.'sueltas'" name="sueltas" type="number" min="0"
                    placeholder="0" x-model="sueltas" x-bind:step="{{ $paso }}"
                    x-bind:disabled="!({{ $hayEmpaque }})" />
                <p class="{{ $nota }}">las que vinieron fuera</p>
            </div>
        </div>

        {{-- La cuenta, hecha delante de quien la iba a hacer con calculadora. --}}
        <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
            <p class="text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="text-lg font-semibold text-gray-800 dark:text-white/90">
                <span x-text="total"></span> <span x-text="{{ $unidad }}"></span>
            </p>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400"
                x-text="(Number(empaques) || 0) + ' × ' + ({{ $contenido }}) + ' + ' + (Number(sueltas) || 0) + ' sueltas'">
            </p>
        </div>
    </div>

    @if ($error)
        <p class="text-theme-xs text-error-600 dark:text-error-400">{{ $error }}</p>
    @endif
</div>
