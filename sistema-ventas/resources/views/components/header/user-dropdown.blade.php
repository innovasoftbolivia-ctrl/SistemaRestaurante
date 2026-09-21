@php
    $usuario = auth()->user();
@endphp

<div class="relative" x-data="{ abierto: false }" @click.away="abierto = false">
    <button type="button" class="flex items-center text-gray-700 dark:text-gray-400" @click="abierto = !abierto">
        <x-ui.inicial :nombre="$usuario->nombre_completo" size="md" class="mr-3" marca />

        <span class="block mr-1 font-medium text-theme-sm">{{ $usuario->empleado?->nombres ?? $usuario->usuario }}</span>

        <svg aria-hidden="true" class="w-5 h-5 transition-transform duration-200" :class="{ 'rotate-180': abierto }" fill="none"
            stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="abierto" x-cloak x-transition:enter="transition ease-salida duration-150"
        x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-salida duration-100" x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 z-50 mt-[17px] flex w-[260px] flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark">

        <div>
            <span class="block font-medium text-gray-700 text-theme-sm dark:text-gray-400">
                {{ $usuario->nombre_completo }}
            </span>
            <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                {{ $usuario->usuario }} · {{ $usuario->rol?->nombre }}
            </span>
        </div>

        <ul class="flex flex-col gap-1 pt-4 pb-3 border-b border-gray-200 dark:border-gray-800">
            <li>
                <a href="{{ route('perfil.edit') }}"
                    class="flex items-center gap-3 px-3 py-2 font-medium text-gray-700 rounded-lg group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300">
                    <span class="text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300">
                        {!! \App\Support\Menu::icono('perfil') !!}
                    </span>
                    Mi perfil
                </a>
            </li>
        </ul>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit"
                class="flex items-center w-full gap-3 px-3 py-2 font-medium text-gray-700 rounded-lg group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300">
                <span class="text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300">
                    <svg aria-hidden="true" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                        </path>
                    </svg>
                </span>
                Cerrar sesión
            </button>
        </form>
    </div>
</div>
