@props([
    'size' => 'md',
    'variant' => 'primary',
    'startIcon' => null,
    'endIcon' => null,
    'href' => null,
    'disabled' => false,
])

@php
    // `focus-visible` y no `focus`: el anillo aparece al llegar con el tabulador,
    // pero no al hacer clic con el ratón, donde solo sería ruido.
    $base = 'inline-flex items-center justify-center font-medium gap-2 rounded-lg transition '
        .'focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 '
        .'focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-900';

    $sizeMap = [
        'xs' => 'px-3 py-2 text-xs',
        'sm' => 'px-4 py-3 text-sm',
        'md' => 'px-5 py-3.5 text-sm',
    ];

    $variantMap = [
        'primary' => 'bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600 disabled:bg-brand-300',
        'outline' => 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03] dark:hover:text-gray-300',
        'danger' => 'bg-error-500 text-white shadow-theme-xs hover:bg-error-600',
        'success' => 'bg-success-500 text-white shadow-theme-xs hover:bg-success-600',
        'ghost' => 'text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-300',
    ];

    $classes = trim(implode(' ', [
        $base,
        $sizeMap[$size] ?? $sizeMap['md'],
        $variantMap[$variant] ?? $variantMap['primary'],
        $disabled ? 'cursor-not-allowed opacity-50' : '',
    ]));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($startIcon) <span class="flex items-center">{!! $startIcon !!}</span> @endif
        {{ $slot }}
        @if ($endIcon) <span class="flex items-center">{!! $endIcon !!}</span> @endif
    </a>
@else
    <button {{ $attributes->merge(['class' => $classes, 'type' => $attributes->get('type', 'button')]) }}
        @disabled($disabled)>
        @if ($startIcon) <span class="flex items-center">{!! $startIcon !!}</span> @endif
        {{ $slot }}
        @if ($endIcon) <span class="flex items-center">{!! $endIcon !!}</span> @endif
    </button>
@endif
