<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dónde vive la lógica de negocio de la base
    |--------------------------------------------------------------------------
    |
    | El esquema apoya reglas críticas en 6 procedimientos almacenados y 7
    | triggers: descontar stock y escribir el kardex al vender, recalcular los
    | totales, tomar el correlativo del comprobante con bloqueo de fila, y
    | calcular el arqueo al cerrar caja.
    |
    | En un servidor propio eso es lo correcto: la regla se cumple aunque
    | alguien entre por fuera de la aplicación, y el bloqueo de fila lo hace el
    | motor. Pero un hosting compartido gratuito no deja crear procedimientos
    | ni triggers (pide el privilegio SUPER), y ahí el sistema no arrancaría.
    |
    | Con `LOGICA_EN_PHP=true` esas mismas reglas las ejecuta
    | `App\Services\ReglasEnPhp`, paso por paso, dentro de la misma transacción.
    | Es la vía para una demo en hosting gratuito.
    |
    | Cuál usar:
    |   false (por omisión) -> servidor propio / Docker. Es la vía probada y
    |                          la que conviene para datos reales.
    |   true                -> hosting sin procedimientos ni triggers.
    |
    | Las dos vías corren la MISMA batería de pruebas, justamente para que no
    | se separen con el tiempo.
    |
    */

    'logica_en_php' => env('LOGICA_EN_PHP', false),

    /*
    |--------------------------------------------------------------------------
    | Respaldos
    |--------------------------------------------------------------------------
    |
    | Dónde se guardan los respaldos que hace App\Services\Respaldos. Por
    | omisión, storage/app/respaldos: fuera de public/ y fuera de git. En
    | Docker de producción esa carpeta es un volumen, para que sobreviva a
    | reconstruir la imagen.
    |
    */

    'respaldos' => [
        'ruta' => env('RESPALDOS_RUTA'),
    ],

    /*
    | Filas máximas de un PDF (ver App\Support\TopePdf). 250 entra holgado en
    | 256 MB de memoria; con más memoria se puede subir.
    */

    'pdf_max_filas' => (int) env('PDF_MAX_FILAS', 250),

];
