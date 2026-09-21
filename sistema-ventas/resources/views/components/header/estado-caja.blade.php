@php
    // El estado de la caja de quien está en sesión, a la vista en todas las
    // pantallas: sin esto había que entrar a Caja para saber si el turno
    // estaba abierto. Solo para quien abre caja; la cocina no la usa.
    $usuario = auth()->user();
    $puedeAbrir = $usuario?->tienePermiso('caja.abrir');
    $sesion = $puedeAbrir ? \App\Services\Cajas::sesionDe($usuario) : null;
@endphp

@if ($puedeAbrir)
    @if ($sesion)
        <a href="{{ route('caja.show', $sesion) }}" data-estado-caja="abierta"
            class="hidden items-center gap-2 rounded-full bg-success-50 px-3 py-1.5 text-theme-sm font-medium text-success-700 transition hover:bg-success-100 sm:inline-flex dark:bg-success-500/15 dark:text-success-500 dark:hover:bg-success-500/20">
            <span aria-hidden="true" class="h-2 w-2 rounded-full bg-success-500"></span>
            {{ $sesion->caja?->nombre ?? 'Caja' }} abierta
        </a>
    @else
        <a href="{{ route('caja.index') }}" data-estado-caja="cerrada"
            class="hidden items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-theme-sm font-medium text-gray-600 transition hover:bg-gray-200 sm:inline-flex dark:bg-white/[0.06] dark:text-gray-300 dark:hover:bg-white/10">
            <span aria-hidden="true" class="h-2 w-2 rounded-full bg-gray-400"></span>
            Sin caja abierta
        </a>
    @endif
@endif
