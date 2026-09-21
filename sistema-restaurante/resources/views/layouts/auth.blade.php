<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    @include('layouts.partials.head')
</head>

<body class="dark:bg-gray-900" x-data="{ loaded: true }">

    <x-common.preloader />

    <main>
        @yield('content')
    </main>

    @stack('scripts')
</body>

</html>
