@props([
    // Lo que se lee primero: la frase corta que ya decía la lista vacía.
    'titulo',
    // Por qué está vacío o qué va a aparecer ahí. Opcional.
    'detalle' => null,
    // Un dibujo, no una ilustración: lista, menú, búsqueda o cajón.
    'icono' => 'lista',
])

{{--
    Una lista vacía que dice qué hacer.

    Al abrir el local todo está en cero, y un renglón gris suelto se lee como
    una falla del sistema. Con el ícono, la frase y —cuando hay uno— el botón
    que corresponde, la misma pantalla se vuelve el primer paso del día.

    El botón va en el slot, para que cada pantalla ponga el suyo (y respete sus
    permisos):

        <x-common.vacio titulo="Todavía no hay pedidos hoy" detalle="...">
            <x-ui.button size="xs" :href="route('pos.index')">Ir al mostrador</x-ui.button>
        </x-common.vacio>
--}}

@php
    $trazos = [
        'lista' => 'M4 7h16M4 12h16M4 17h10',
        'menu' => 'M4 6h16M4 12h10M4 18h7M17 15l2 2 3-4',
        'busqueda' => 'M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z',
        'caja' => 'M3 7l9-4 9 4v10l-9 4-9-4V7zM3 7l9 4 9-4M12 11v10',
    ];
    $trazo = $trazos[$icono] ?? $trazos['lista'];
@endphp

<div {{ $attributes->merge(['class' => 'px-6 py-8 text-center']) }}>
    <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 dark:bg-brand-500/15">
        <svg class="h-6 w-6 text-brand-500 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
            stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $trazo }}" />
        </svg>
    </span>

    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $titulo }}</p>

    @if ($detalle)
        <p class="mx-auto mt-1 max-w-sm text-theme-xs text-gray-500 dark:text-gray-400">{{ $detalle }}</p>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
