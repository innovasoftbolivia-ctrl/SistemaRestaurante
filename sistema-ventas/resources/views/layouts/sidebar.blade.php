@php
    use App\Support\Menu;

    $grupos = Menu::grupos();
    $negocio = \App\Support\Config::negocio();
    $cuenta = auth()->user();
@endphp

<aside id="sidebar"
    {{-- `transition-[width,transform]` y no `transition-all`: son las dos únicas
         propiedades que cambian aquí, y con `all` el navegador vigila todas. --}}
    class="fixed flex flex-col mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-[width,transform] duration-300 ease-in-out z-99999 border-r border-gray-200 overscroll-contain"
    :class="{
        'w-[290px]': $store.sidebar.isExpanded || $store.sidebar.isMobileOpen || $store.sidebar.isHovered,
        'w-[90px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full xl:translate-x-0': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">

    <!-- Logo -->
    <div class="pt-8 pb-7 flex"
        :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen)
            ? 'xl:justify-center'
            : 'justify-start'">
        {{-- El nombre del negocio (Configuración), como en el inicio de sesión:
             es lo que el personal reconoce. Plegada, queda el ícono. --}}
        <a href="{{ url(Menu::inicio()) }}" class="flex min-w-0 items-center gap-3" data-marca-negocio>
            <x-common.icono-negocio />
            <span x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                class="min-w-0 leading-tight">
                <span class="block truncate text-base font-semibold text-gray-900 dark:text-white">{{ $negocio }}</span>
                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">Pedidos y caja</span>
            </span>
        </a>
    </div>

    <!-- Navegación -->
    <div class="flex flex-col overflow-y-auto overscroll-contain duration-300 ease-linear no-scrollbar">
        <nav class="mb-6">
            <div class="flex flex-col gap-4">
                @foreach ($grupos as $grupo)
                    <div>
                        <h2 class="mb-4 text-xs uppercase flex leading-[20px] text-gray-400"
                            :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen)
                                ? 'lg:justify-center'
                                : 'justify-start'">
                            <template x-if="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                                <span>{{ $grupo['title'] }}</span>
                            </template>
                            <template x-if="!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen">
                                <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M5.99915 10.2451C6.96564 10.2451 7.74915 11.0286 7.74915 11.9951V12.0051C7.74915 12.9716 6.96564 13.7551 5.99915 13.7551C5.03265 13.7551 4.24915 12.9716 4.24915 12.0051V11.9951C4.24915 11.0286 5.03265 10.2451 5.99915 10.2451ZM17.9991 10.2451C18.9656 10.2451 19.7491 11.0286 19.7491 11.9951V12.0051C19.7491 12.9716 18.9656 13.7551 17.9991 13.7551C17.0326 13.7551 16.2491 12.9716 16.2491 12.0051V11.9951C16.2491 11.0286 17.0326 10.2451 17.9991 10.2451ZM13.7491 11.9951C13.7491 11.0286 12.9656 10.2451 11.9991 10.2451C11.0326 10.2451 10.2491 11.0286 10.2491 11.9951V12.0051C10.2491 12.9716 11.0326 13.7551 11.9991 13.7551C12.9656 13.7551 13.7491 12.9716 13.7491 12.0051V11.9951Z" fill="currentColor"/>
                                </svg>
                            </template>
                        </h2>

                        <ul class="flex flex-col gap-1">
                            @foreach ($grupo['items'] as $item)
                                @php $activo = Menu::esActivo($item['path']); @endphp
                                <li>
                                    {{-- `aria-label` en el enlace, no solo el texto visible: el texto se
                                         oculta con `x-show` (queda fuera del árbol de accesibilidad) cuando
                                         la barra está colapsada a solo íconos, que es el estado por
                                         omisión. Sin esto, cada enlace llegaba sin nombre a un lector de
                                         pantalla —el ícono es `aria-hidden`— y no había forma de saber a
                                         dónde iba ninguno de los enlaces de navegación. --}}
                                    <a href="{{ url($item['path']) }}"
                                        aria-label="{{ $item['name'] }}"
                                        class="menu-item group {{ $activo ? 'menu-item-active' : 'menu-item-inactive' }}"
                                        :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen)
                                            ? 'xl:justify-center'
                                            : 'justify-start'">
                                        <span class="{{ $activo ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                            {!! Menu::icono($item['icon']) !!}
                                        </span>
                                        <span x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                                            class="menu-item-text">
                                            {{ $item['name'] }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>

        <!-- Quién está en sesión: una fila discreta. Salir también está en el
             menú del usuario, arriba a la derecha. -->
        <div x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
            x-transition class="mt-auto pb-6">
            <div class="flex items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-800" data-ficha-sesion>
                <x-ui.inicial :nombre="$cuenta->nombre_completo" size="sm" marca />
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-theme-sm font-semibold text-gray-900 dark:text-white">{{ $cuenta->nombre_completo }}</p>
                    <p class="truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $cuenta->rol?->nombre }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" aria-label="Cerrar sesión" title="Cerrar sesión"
                        class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
                        </svg>
                    </button>
                </form>
            </div>
            @if (filled(config('ventas.desarrollado_por')))
                <p class="mt-4 text-center text-theme-xs text-gray-400 dark:text-gray-500" data-desarrollado-por>
                    Desarrollado por <span class="font-semibold text-brand-500 dark:text-brand-300">{{ config('ventas.desarrollado_por') }}</span>
                </p>
            @endif
        </div>
    </div>
</aside>
