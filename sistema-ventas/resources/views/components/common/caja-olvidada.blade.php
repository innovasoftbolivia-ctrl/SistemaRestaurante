@php
    // Ver App\Support\CajasOlvidadas: todos los turnos olvidados para quien puede
    // cerrarlos, solo el propio para el cajero, nada para el resto.
    $usuario = auth()->user();
    $olvidadas = \App\Support\CajasOlvidadas::para($usuario);
    $puedeCerrar = $usuario?->tienePermiso('caja.cerrar') ?? false;
    $horas = \App\Support\CajasOlvidadas::HORAS;
@endphp

{{-- Una franja de una línea, no un recuadro: sale en todas las pantallas y
     no debe tapar el trabajo. Por qué importa lo explica la pantalla del
     turno, a la que lleva el enlace. --}}
@if ($olvidadas->isNotEmpty())
    <div role="status" data-aviso="caja-olvidada"
        class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-warning-200 bg-warning-50 px-4 py-2.5 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-orange-300">
        <svg aria-hidden="true" class="flex-none" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7.5V12l3 2" />
        </svg>

        @if ($puedeCerrar)
            <span class="font-semibold">
                {{ $olvidadas->count() === 1
                    ? 'Hay un turno de caja abierto hace más de '.$horas.' horas'
                    : 'Hay '.$olvidadas->count().' turnos de caja abiertos hace más de '.$horas.' horas' }}
            </span>
            @foreach ($olvidadas as $sesion)
                <span class="text-warning-700 dark:text-orange-300/90">
                    {{ $sesion->caja?->nombre ?? 'Caja' }} · {{ $sesion->usuarioApertura?->usuario ?? '—' }} ·
                    desde el {{ $sesion->fecha_apertura->format('d/m H:i') }}
                    <a href="{{ route('caja.show', $sesion) }}"
                        class="ml-1 font-semibold text-warning-800 underline underline-offset-2 hover:no-underline dark:text-orange-200">Revisar y cerrar</a>
                </span>
            @endforeach
        @else
            @php $sesion = $olvidadas->first(); @endphp
            <span>
                <span class="font-semibold">Tu turno en {{ $sesion->caja?->nombre ?? 'la caja' }} sigue abierto desde el
                    {{ $sesion->fecha_apertura->format('d/m/Y') }} a las {{ $sesion->fecha_apertura->format('H:i') }}.</span>
                Pide a un administrador que lo cierre contando el cajón contigo.
            </span>
        @endif
    </div>
@endif
