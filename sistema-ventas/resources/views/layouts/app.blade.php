<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    @include('layouts.partials.head')
</head>

<body class="dark:bg-gray-900"
    x-data="{ loaded: true }"
    x-init="
        const checkMobile = () => {
            if (window.innerWidth < 1280) {
                $store.sidebar.setMobileOpen(false);
                $store.sidebar.isExpanded = false;
            } else {
                $store.sidebar.isMobileOpen = false;
                $store.sidebar.isExpanded = true;
            }
        };
        window.addEventListener('resize', checkMobile);
    ">

    {{-- Primer elemento enfocable de la página: con el tabulador permite saltarse
         la barra lateral y la cabecera de una vez. Solo se ve al recibir el foco. --}}
    <a href="#contenido"
        class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-999999 focus:rounded-lg focus:bg-brand-500 focus:px-4 focus:py-2 focus:text-white">
        Saltar al contenido
    </a>

    <x-common.preloader />

    <div class="min-h-screen">
        @include('layouts.backdrop')
        @include('layouts.sidebar')

        {{--
            La barra lateral es `fixed`, así que el contenido no va en un flex: se
            aparta con un margen del ancho de la barra (ver `.contenido-principal`).

            El ancho viaja en una variable CSS con su valor ya puesto desde el
            servidor, no en clases que Alpine añade después. Importa: si la primera
            pintura sale sin el margen, todo lo que mida su ancho al arrancar —el
            gráfico, por ejemplo— se queda con el ancho equivocado y la página
            entera acaba con barra de desplazamiento horizontal.

            Con clases tampoco funcionaría el plegado: Alpine solo quita las clases
            que puso él, y una `xl:ml-[290px]` escrita en el HTML nunca se iría.
        --}}
        <div class="contenido-principal min-w-0"
            style="--ancho-barra: 290px"
            :style="{
                '--ancho-barra': ($store.sidebar.isExpanded || $store.sidebar.isHovered) ? '290px' : '90px'
            }">

            @include('layouts.app-header')

            {{-- <main> marca dónde empieza el contenido de la pantalla, para que
                 un lector de pantalla pueda saltarse la barra lateral y la
                 cabecera de una vez. --}}
            <main id="contenido" class="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6">
                <x-common.page-breadcrumb :page-title="$title ?? ''" :trail="$trail ?? []" />

                <x-common.flash />
                <x-common.caja-olvidada />

                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>

</html>
