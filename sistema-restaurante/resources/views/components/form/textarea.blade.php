@props([
    'name' => null,
    'value' => null,
    'rows' => 3,
])

@php
    $tieneError = $name && $errors->has($name);

    $borde = $tieneError
        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-700'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800';
@endphp

<textarea name="{{ $name }}" rows="{{ $rows }}"
    {{ $attributes->merge([
        'class' => 'dark:bg-dark-900 shadow-theme-xs w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm '
            .'text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 '
            .'dark:text-white/90 dark:placeholder:text-white/30 '.$borde,
    ]) }}>{{ old($name, $value) }}</textarea>
