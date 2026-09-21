@extends('layouts.auth')

{{--
    Sin esta vista, un 405 se escapa a la página por defecto de Symfony: en
    inglés, sin la marca del negocio y con el aspecto de que el sistema se
    rompió. Se llega ahí con solo abrir en el navegador una dirección que el
    sistema atiende únicamente por POST —`/logout`, por ejemplo—, así que no
    es un caso raro.
--}}

@section('content')
    <x-common.error-page codigo="405" titulo="Esa dirección no se abre así"
        mensaje="La página existe, pero se llega a ella desde un botón del sistema, no escribiendo la dirección. Vuelve al inicio y entra por el menú." />
@endsection
