@props([
    'titulo' => 'En construcción',
    'size' => 'md',
])

{{--
    Marca una parte del sistema que todavía no está definida, para que se vea
    que está pendiente y no se confunda con algo terminado. `size="sm"` da una
    etiqueta suelta; el tamaño normal, un aviso con explicación en el slot.
--}}

@if ($size === 'sm')
    <span
        {{ $attributes->merge([
            'class' => 'inline-flex items-center gap-1 rounded-full border border-dashed border-warning-500 '
                .'px-2.5 py-0.5 text-theme-xs font-medium text-warning-700 dark:text-orange-400',
        ]) }}>
        <svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 24 24" fill="none">
            <path d="M12 8v5M12 16.5v.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <path d="M10.3 3.9 2.4 17.4a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"
                stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
        </svg>
        {{ $titulo }}
    </span>
@else
    <div
        {{ $attributes->merge([
            'class' => 'rounded-xl border border-dashed border-warning-500 bg-warning-50 p-4 '
                .'dark:border-warning-500/40 dark:bg-warning-500/10',
        ]) }}>
        <div class="flex items-start gap-3">
            <svg aria-hidden="true" class="mt-0.5 h-5 w-5 flex-none text-warning-700 dark:text-orange-400" viewBox="0 0 24 24"
                fill="none">
                <path d="M12 8v5M12 16.5v.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                <path d="M10.3 3.9 2.4 17.4a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"
                    stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
            </svg>
            <div>
                <p class="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $titulo }}</p>
                <div class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $slot }}</div>
            </div>
        </div>
    </div>
@endif
