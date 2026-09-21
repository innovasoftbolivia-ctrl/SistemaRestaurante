@props([
    'name' => null,
    'value' => null,
    'opciones' => [],
    'placeholder' => null,
])

@php
    $tieneError = $name && $errors->has($name);

    // Un select sin `name` es de apoyo —no se envía— y por tanto no tiene valor
    // anterior que recuperar. Hace falta cortar aquí: `old(null)` devuelve TODO
    // el input anterior como array, y la comparación de más abajo revienta con
    // «Array to string conversion». Mismo criterio que en `form/input`.
    $actual = $name ? old($name, $value) : $value;

    $borde = $tieneError
        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-700'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800';
@endphp

<select name="{{ $name }}"
    {{ $attributes->merge([
        'class' => 'dark:bg-dark-900 shadow-theme-xs h-11 w-full appearance-none rounded-lg border bg-transparent '
            .'bg-[url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'%236B7280\'%3E%3Cpath stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M19 9l-7 7-7-7\'/%3E%3C/svg%3E")] '
            .'bg-[length:1.1rem] bg-[right_0.9rem_center] bg-no-repeat px-4 py-2.5 pr-10 text-sm text-gray-800 '
            .'focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 '.$borde,
    ]) }}>
    @if ($placeholder)
        <option value="" @selected($actual === null || $actual === '')>{{ $placeholder }}</option>
    @endif

    @foreach ($opciones as $valor => $etiqueta)
        <option value="{{ $valor }}" @selected((string) $actual === (string) $valor)>{{ $etiqueta }}</option>
    @endforeach

    {{ $slot }}
</select>
