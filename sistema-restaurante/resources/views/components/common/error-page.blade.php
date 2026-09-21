@props(['codigo', 'titulo', 'mensaje'])

{{--
    Página de error independiente: no depende de sesión ni de datos de
    negocio, porque un error puede ocurrir antes de que cualquiera de esos
    dos exista (sesión caída, base sin responder). Reutiliza el layout de
    autenticación —sin barra lateral— por la misma razón.
--}}
<div class="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
    <div class="relative flex h-screen w-full flex-col items-center justify-center sm:p-0 dark:bg-gray-900">
        <x-common.common-grid-shape />

        <div class="z-1 mx-auto flex max-w-md flex-col items-center text-center">
            {{-- Solo el ícono, sin el nombre del negocio: una página de error
                 puede salir justo porque la base no responde. --}}
            <x-common.icono-negocio class="mb-8" />

            <p class="text-title-md sm:text-title-lg font-bold text-brand-500 dark:text-brand-400">{{ $codigo }}</p>
            <h1 class="text-title-sm sm:text-title-md mt-2 mb-3 font-semibold text-gray-800 dark:text-white/90">
                {{ $titulo }}
            </h1>
            <p class="mb-8 text-sm text-gray-500 dark:text-gray-400">
                {{ $mensaje }}
            </p>

            <x-ui.button href="/">Volver al inicio</x-ui.button>
        </div>
    </div>
</div>
