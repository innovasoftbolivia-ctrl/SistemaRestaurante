@extends('layouts.auth')

@section('content')
    <x-common.error-page codigo="404" titulo="Página no encontrada"
        mensaje="La dirección no existe o se movió. Revisa el enlace, o vuelve al inicio." />
@endsection
