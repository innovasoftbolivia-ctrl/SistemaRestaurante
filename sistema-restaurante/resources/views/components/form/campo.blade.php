@props([
    'label' => null,
    'for' => null,
    'name' => null,
    'help' => null,
    'required' => false,
])

@php
    $campo = $name ?? $for;
    $error = $campo ? $errors->first($campo) : null;
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($label)
        <label for="{{ $for }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ $label }}@if ($required)<span class="text-error-600 dark:text-error-400">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        <p class="mt-1.5 text-theme-xs text-error-600 dark:text-error-400">{{ $error }}</p>
    @elseif ($help)
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
