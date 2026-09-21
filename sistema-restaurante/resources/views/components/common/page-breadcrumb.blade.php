@props([
    'pageTitle' => '',
    'trail' => [],
])

@if ($pageTitle)
    <div data-migas class="flex flex-wrap items-center justify-between gap-3 mb-6">
        {{-- Único h1 de la pantalla: es el nombre de la página. Los títulos de
             tarjeta que vienen debajo son h2. --}}
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">
            {{ $pageTitle }}
        </h1>
        {{-- La ruta solo cuando hay de dónde venir (Menú › Agregar al menú):
             sin eso repetía el título a la derecha. --}}
        @if ($trail)
        <nav aria-label="Ruta de navegación">
            <ol class="flex items-center gap-1.5">
                @foreach ($trail as $etiqueta => $url)
                    <li>
                        <a class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                            href="{{ $url }}">
                            {{ $etiqueta }}
                            <svg aria-hidden="true" class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke-width="1.2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                @endforeach
                <li class="text-sm text-gray-800 dark:text-white/90">{{ $pageTitle }}</li>
            </ol>
        </nav>
        @endif
    </div>
@endif
