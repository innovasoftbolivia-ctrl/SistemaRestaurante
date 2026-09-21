@props([
    'name' => null,
    'value' => null,
    'type' => 'text',
])

@php
    $tieneError = $name && $errors->has($name);

    // En un móvil, `type="number"` a secas abre un teclado numérico sin coma
    // decimal: para escribir 12.50 hay que cambiar de teclado. `inputmode`
    // decide qué teclas se ven, y aquí casi todo lo numérico son importes y
    // cantidades. Se puede sobrescribir pasando `inputmode` en la llamada.
    $modo = $attributes->get('inputmode') ?? ($type === 'number' ? 'decimal' : null);

    $borde = $tieneError
        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-700'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800';
@endphp

{{-- Un campo sin `name` no se envía (es de apoyo, como el precio de estante),
     así que tampoco tiene valor anterior que recuperar. --}}
<input type="{{ $type }}" name="{{ $name }}" value="{{ $name ? old($name, $value) : $value }}"
    @if ($modo) inputmode="{{ $modo }}" @endif
    {{ $attributes->except('inputmode')->merge([
        'class' => 'dark:bg-dark-900 shadow-theme-xs h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 '
            .'text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden '
            .'dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 '.$borde,
    ]) }} />
