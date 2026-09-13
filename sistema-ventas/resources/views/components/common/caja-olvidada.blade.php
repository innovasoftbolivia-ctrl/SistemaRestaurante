@php
    // Ver App\Support\CajasOlvidadas: todos los turnos olvidados para quien puede
    // cerrarlos, solo el propio para el cajero, nada para el resto.
    $usuario = auth()->user();
    $olvidadas = \App\Support\CajasOlvidadas::para($usuario);
    $puedeCerrar = $usuario?->tienePermiso('caja.cerrar') ?? false;
    $horas = \App\Support\CajasOlvidadas::HORAS;
@endphp

@if ($olvidadas->isNotEmpty())
    <div class="mb-6" data-aviso="caja-olvidada">
        @if ($puedeCerrar)
            <x-ui.alert variant="warning"
                :title="$olvidadas->count() === 1
                    ? 'Hay un turno de caja abierto hace más de '.$horas.' horas'
                    : 'Hay '.$olvidadas->count().' turnos de caja abiertos hace más de '.$horas.' horas'">
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Un turno que abarca más de una jornada ya no sirve para cuadrar el efectivo. Ciérralo contando el
                    cajón junto al cajero.
                </p>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach ($olvidadas as $sesion)
                        <li class="flex flex-wrap items-baseline gap-x-2 text-gray-700 dark:text-gray-300">
                            <span class="font-medium">{{ $sesion->caja?->nombre ?? 'Caja' }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                abierta por {{ $sesion->usuarioApertura?->usuario ?? '—' }}
                                el {{ $sesion->fecha_apertura->format('d/m/Y') }} a las {{ $sesion->fecha_apertura->format('H:i') }}
                                ({{ $sesion->fecha_apertura->diffForHumans() }})
                            </span>
                            <a href="{{ route('caja.show', $sesion) }}" class="font-medium text-brand-500 hover:underline">
                                Revisar y cerrar
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @else
            @php $sesion = $olvidadas->first(); @endphp
            <x-ui.alert variant="warning"
                :title="'Tu turno en '.($sesion->caja?->nombre ?? 'la caja').' sigue abierto desde el '.$sesion->fecha_apertura->format('d/m/Y').' a las '.$sesion->fecha_apertura->format('H:i')">
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Pasaron más de {{ $horas }} horas. Pide a un administrador que lo cierre contando el cajón contigo:
                    mientras siga abierto, el arqueo mezcla más de una jornada.
                </p>
            </x-ui.alert>
        @endif
    </div>
@endif
