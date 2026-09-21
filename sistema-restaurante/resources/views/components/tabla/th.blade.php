@props([
    // Clave que viaja en la URL. Sin ella la cabecera no se ordena.
    'clave' => null,
    // Marca la columna por la que ordena la pantalla cuando la URL no pide nada.
    'defecto' => false,
    // Sentido inicial al pulsarla por primera vez. Para fechas e importes suele
    // interesar «lo más alto primero».
    'inicial' => 'asc',
    'derecha' => false,
])

@php
    $alineacion = $derecha ? 'text-right' : 'text-left';
    $base = 'px-5 py-3 '.$alineacion.' text-theme-xs font-medium';

    $pedida = request()->query('orden');
    $activa = $clave && ($pedida === $clave || ($pedida === null && $defecto));

    if ($activa) {
        if (request()->has('dir')) {
            $dirActual = mb_strtolower((string) request()->query('dir')) === 'desc' ? 'desc' : 'asc';
        } else {
            // Misma regla que `OrdenaTablas::orden()`: el sentido inicial de la
            // columna solo aplica al entrar sin nada en la URL. Si vienen con
            // `?orden=` pero sin `dir`, ascendente. Las dos reglas tienen que
            // ser la misma o la flecha contradice a los datos.
            $dirActual = $pedida === null ? $inicial : 'asc';
        }

        // Pulsar la columna activa invierte el sentido.
        $siguiente = $dirActual === 'asc' ? 'desc' : 'asc';
    } else {
        $dirActual = null;
        $siguiente = $inicial;
    }

    // `page => null` devuelve a la primera página: al cambiar el orden, seguir
    // en la página 4 no tiene sentido. El resto de filtros se conserva.
    $url = $clave
        ? request()->fullUrlWithQuery(['orden' => $clave, 'dir' => $siguiente, 'page' => null])
        : null;

    $ariaSort = $activa ? ($dirActual === 'asc' ? 'ascending' : 'descending') : 'none';
@endphp

@unless ($clave)
    <th {{ $attributes->merge(['class' => $base.' text-gray-500 dark:text-gray-400']) }}>
        {{ $slot }}
    </th>
@else
    <th {{ $attributes->merge(['class' => $base.' '.($activa ? 'text-brand-500 dark:text-brand-400' : 'text-gray-500 dark:text-gray-400')]) }}
        aria-sort="{{ $ariaSort }}">
        {{-- Enlace y no botón: así funciona el Ctrl+clic, el «abrir en pestaña
             nueva» y el botón atrás del navegador. --}}
        <a href="{{ $url }}"
            class="group inline-flex items-center gap-1.5 rounded transition hover:text-brand-500 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-brand-500/40 {{ $derecha ? 'flex-row-reverse' : '' }}">
            <span>{{ $slot }}</span>

            @if (! $activa)
                {{-- Doble flecha tenue: solo aparece al pasar el ratón, para
                     insinuar que la columna se puede ordenar sin ensuciar la
                     cabecera con siete iconos siempre visibles. --}}
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    class="opacity-0 transition-opacity group-hover:opacity-60 group-focus-visible:opacity-60">
                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            @elseif ($dirActual === 'asc')
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none">
                    <path d="M12 19V5M6 11l6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            @else
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M6 13l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            @endif
        </a>
    </th>
@endunless
