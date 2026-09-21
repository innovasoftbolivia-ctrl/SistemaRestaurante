@php
    // Resultado de la última acción; se cierra solo a los pocos segundos.
    //
    // `aviso` es lo que no salió mal pero tampoco hizo nada: un conteo que
    // coincide con el sistema, una configuración guardada sin cambios. Antes
    // no se mostraba en ningún lado, así que quien apretaba Guardar no veía
    // respuesta alguna y no sabía si el sistema lo había escuchado.
    $exito = session('exito');
    $error = session('error');
    $aviso = session('aviso');
    $errores = $errors->any();

    [$variante, $titulo, $mensaje] = match (true) {
        (bool) $error => ['error', 'No se pudo completar', $error],
        (bool) $exito => ['success', 'Listo', $exito],
        (bool) $aviso => ['info', 'Aviso', $aviso],
        default => [null, null, null],
    };
@endphp

@if ($mensaje)
    <div x-data="{ visible: true }" x-show="visible" x-transition
        x-init="setTimeout(() => visible = false, 6000)" class="mb-6">
        <x-ui.alert :variant="$variante" :title="$titulo" :message="$mensaje" />
    </div>
@endif

@if ($errores && ! $error)
    <div class="mb-6">
        <x-ui.alert variant="error" title="Revisa los datos ingresados">
            <ul class="mt-2 space-y-1 text-sm text-gray-500 dark:text-gray-400">
                @foreach ($errors->all() as $mensaje)
                    <li>· {{ $mensaje }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    </div>
@endif
