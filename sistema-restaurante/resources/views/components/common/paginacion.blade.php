@props(['paginador'])

<div
    class="flex flex-col items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 sm:flex-row dark:border-gray-800">
    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
        @if ($paginador->total() > 0)
            Mostrando {{ $paginador->firstItem() }}–{{ $paginador->lastItem() }} de {{ $paginador->total() }}
        @else
            Sin resultados
        @endif
    </p>

    @if ($paginador->hasPages())
        <nav class="flex flex-wrap items-center gap-1">
            {{-- Botón deshabilitado y no un <span> gris: así el navegador y los
                 lectores de pantalla saben que está inactivo, en vez de leerlo
                 como texto suelto. La WCAG exime del contraste a los controles
                 inactivos, pero se sube un tono para que siga viéndose. --}}
            @if ($paginador->onFirstPage())
                <button type="button" disabled
                    class="cursor-not-allowed rounded-lg px-3 py-1.5 text-theme-sm text-gray-400 dark:text-gray-600">Anterior</button>
            @else
                <a href="{{ $paginador->previousPageUrl() }}"
                    class="rounded-lg px-3 py-1.5 text-theme-sm text-gray-600 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05]">Anterior</a>
            @endif

            @foreach ($paginador->getUrlRange(max(1, $paginador->currentPage() - 2), min($paginador->lastPage(), $paginador->currentPage() + 2)) as $pagina => $url)
                <a href="{{ $url }}"
                    class="rounded-lg px-3.5 py-1.5 text-theme-sm transition {{ $pagina == $paginador->currentPage()
                        ? 'bg-brand-500 font-medium text-white'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05]' }}">
                    {{ $pagina }}
                </a>
            @endforeach

            @if ($paginador->hasMorePages())
                <a href="{{ $paginador->nextPageUrl() }}"
                    class="rounded-lg px-3 py-1.5 text-theme-sm text-gray-600 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05]">Siguiente</a>
            @else
                <button type="button" disabled
                    class="cursor-not-allowed rounded-lg px-3 py-1.5 text-theme-sm text-gray-400 dark:text-gray-600">Siguiente</button>
            @endif
        </nav>
    @endif
</div>
