@extends('layouts.auth')

@section('content')
    <x-common.error-page codigo="503" titulo="En mantenimiento"
        mensaje="El sistema está en mantenimiento por un momento. Vuelve a intentar en unos minutos." />
@endsection
