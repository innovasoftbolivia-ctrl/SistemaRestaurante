@extends('layouts.auth')

@section('content')
    <x-common.error-page codigo="403" titulo="No tienes acceso"
        mensaje="Tu cuenta no tiene permiso para esto. Si crees que deberías tenerlo, pide a un administrador que revise tu rol." />
@endsection
