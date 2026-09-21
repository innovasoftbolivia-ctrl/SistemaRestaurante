@props([
    'nombre' => '',
    'size' => 'md',
    // La cuenta en sesión va en el azul de la marca; en las listas, cada
    // persona con su color.
    'marca' => false,
])

@php
    // Avatar de iniciales: el sistema no guarda fotos del personal.
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $iniciales = mb_strtoupper(mb_substr($partes[0] ?? '?', 0, 1).mb_substr($partes[1] ?? '', 0, 1));

    $sizeMap = [
        'sm' => 'h-9 w-9 text-xs',
        'md' => 'h-11 w-11 text-sm',
        'lg' => 'h-16 w-16 text-lg',
        'xl' => 'h-20 w-20 text-2xl',
    ];

    // Un color estable por persona, para que se reconozca de una lista a otra.
    $paleta = [
        'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300',
        'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-500',
        'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
        'bg-blue-light-100 text-blue-light-700 dark:bg-blue-light-500/20 dark:text-blue-light-400',
        'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400',
    ];
    $color = $marca ? $paleta[0] : $paleta[crc32($nombre) % count($paleta)];
@endphp

<span
    {{ $attributes->merge([
        'class' => 'inline-flex flex-none items-center justify-center rounded-full font-semibold '
            .($sizeMap[$size] ?? $sizeMap['md']).' '.$color,
    ]) }}
    title="{{ $nombre }}">
    {{ $iniciales }}
</span>
