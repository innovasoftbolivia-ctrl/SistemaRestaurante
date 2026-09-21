<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dónde vive la lógica de negocio de la base
    |--------------------------------------------------------------------------
    |
    | El esquema apoya reglas críticas en 6 procedimientos almacenados y 14
    | triggers: copiar el régimen de impuesto de cada línea, recalcular los
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
        // Una segunda carpeta FUERA del disco del servidor (disco externo,
        // carpeta sincronizada, unidad de red): cada respaldo se copia ahí.
        'copia' => env('RESPALDOS_COPIA'),
        // Y la nube: cada respaldo se sube con rclone a este remoto (`drive:`,
        // el que deja configurado scripts/conectar-drive.sh). Vacío = no se
        // sube. Ver Respaldos::subirALaNube().
        'nube' => env('RESPALDOS_NUBE'),
        'rclone' => env('RCLONE_BIN', 'rclone'),
    ],

    /*
    | Filas máximas de un PDF (ver App\Support\TopePdf). 250 entra holgado en
    | 256 MB de memoria; con más memoria se puede subir.
    */

    'pdf_max_filas' => (int) env('PDF_MAX_FILAS', 250),

    /*
    | Filas máximas de un Excel (ver App\Support\TopeExcel). 20.000 filas de 17
    | columnas caben en 512 MB.
    */

    'excel_max_filas' => (int) env('EXCEL_MAX_FILAS', 20000),

    /*
    | El billete más grande que circula (Bs 200). Si el vuelto llega a ese
    | monto, el cliente entregó un billete que no hacía falta: casi siempre es
    | un error al teclear lo recibido (5000 en vez de 50).
    */

    'billete_mayor' => (float) env('BILLETE_MAYOR', 200),

    /*
    |--------------------------------------------------------------------------
    | Facturación e impuestos a la vista
    |--------------------------------------------------------------------------
    |
    | Mientras el negocio no factura, las pantallas no hablan de impuesto, IVA
    | ni facturas: todo sale como recibo y sin desglose. El código queda entero
    | —el cálculo del IVA y las facturas— y se vuelve a ver poniendo
    | MOSTRAR_FACTURACION=true. El libro de ventas ya no está: se quitó con el
    | inventario, y facturar de nuevo pediría rehacerlo.
    |
    */

    'mostrar_facturacion' => (bool) env('MOSTRAR_FACTURACION', false),

    /*
    |--------------------------------------------------------------------------
    | Quién desarrolló el sistema
    |--------------------------------------------------------------------------
    |
    | Una línea discreta, «Desarrollado por …», en el inicio de sesión y al pie
    | del menú. El nombre grande sigue siendo el del negocio. Vacío = no se
    | muestra, para el cliente que no la quiera.
    |
    */

    'desarrollado_por' => (string) env('DESARROLLADO_POR', 'InnovaDevs'),

];
