@extends('layouts.auth')

@section('content')
    <x-common.error-page codigo="419" titulo="La sesión expiró"
        mensaje="Pasó demasiado tiempo desde que cargó la página. Vuelve a intentar la acción." />
@endsection
