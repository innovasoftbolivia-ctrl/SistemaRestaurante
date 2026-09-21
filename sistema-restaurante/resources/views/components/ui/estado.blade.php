@props([
    'estado' => null,
    'texto' => null,
])

@php
    // Etiqueta de estado: sirve para el vínculo laboral del empleado
    // (ACTIVO/SUSPENDIDO/CESADO) y para el acceso de la cuenta.
    // Los tonos 600 sobre fondo 50 se quedan entre 3.3:1 y 4.4:1, por debajo del
    // 4.5:1 que pide la WCAG para texto. Con el tono 700 la etiqueta se lee sin
    // cambiar el color que representa. En oscuro pasa lo contrario: hace falta
    // un tono más claro, de ahí el 400 en «Cesado».
    $estilos = [
        'ACTIVO' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
        'SUSPENDIDO' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
        'CESADO' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'INDEFINIDO' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'PLAZO_FIJO' => 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400',
        'PARCIAL' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
        'PRACTICAS' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
        'SIN_CUENTA' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
    ];

    $etiquetas = [
        'PLAZO_FIJO' => 'Plazo fijo',
        'PRACTICAS' => 'Prácticas',
        'SIN_CUENTA' => 'Sin cuenta',
    ];

    $clase = $estilos[$estado] ?? 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300';

    // Si el llamador pasa `texto` se respeta tal cual (un nombre de usuario,
    // por ejemplo); solo se da formato a las constantes del esquema.
    $etiqueta = $texto ?? ($etiquetas[$estado] ?? ($estado ? ucfirst(mb_strtolower($estado)) : '—'));
@endphp

<span
    {{ $attributes->merge([
        'class' => 'inline-flex items-center justify-center gap-1 rounded-full px-2.5 py-0.5 text-theme-xs font-medium '.$clase,
    ]) }}>
    {{ $etiqueta }}
</span>
