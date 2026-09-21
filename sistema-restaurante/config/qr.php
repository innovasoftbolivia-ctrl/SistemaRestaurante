<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Con qué se cobra por QR
    |--------------------------------------------------------------------------
    |
    | «simulado» genera un QR real pero sin banco detrás: el pago no ocurre
    | solo, lo confirma el cajero desde la pantalla. Es lo que corresponde
    | mientras no haya convenio, y sirve para demostrar el flujo completo.
    |
    | Cuando el banco entregue credenciales, se pone aquí su código —el mismo
    | que la clave de `pasarelas` de abajo— y no hay que tocar el mostrador.
    |
    */

    'pasarela' => env('QR_PASARELA', 'simulado'),

    /*
    | Cuánto vale un QR antes de vencerse. Los bancos suelen admitir entre 5 y
    | 30 minutos; diez alcanza de sobra en un mostrador y evita que queden
    | cobros viejos dando vueltas.
    */

    'minutos_vigencia' => (int) env('QR_MINUTOS_VIGENCIA', 10),

    /*
    | Cada cuántos segundos el mostrador pregunta si ya pagaron. Bajarlo hace
    | la espera más viva pero castiga al banco con más llamadas.
    */

    'segundos_consulta' => (int) env('QR_SEGUNDOS_CONSULTA', 4),

    /*
    |--------------------------------------------------------------------------
    | Los bancos
    |--------------------------------------------------------------------------
    |
    | Un bloque por banco. Todo lo secreto va en el `.env`, nunca aquí: este
    | archivo se versiona.
    |
    | Lo que hay que pedirle al banco está anotado en `App\Services\Qr\QrBanco`.
    |
    */

    'pasarelas' => [

        /*
        | Banco Económico (BEC QR Connect). Usuario, contraseña, llave y cuenta
        | los entrega el banco. La URL por omisión es la de certificación; en
        | producción el banco da otra.
        */
        'baneco' => [
            'url_base' => env('QR_BANECO_URL', 'https://apimktdesa.baneco.com.bo/ApiGateway'),
            'usuario' => env('QR_BANECO_USUARIO'),
            'password' => env('QR_BANECO_PASSWORD'),
            'llave' => env('QR_BANECO_LLAVE'),
            'cuenta' => env('QR_BANECO_CUENTA'),
            // Opcional: si el banco abona por sucursal (máximo 5 caracteres).
            'sucursal' => env('QR_BANECO_SUCURSAL'),
            // Va delante del número de cobro en `transactionId`, para reconocer
            // en el extracto los QR de esta instalación.
            'prefijo' => env('QR_BANECO_PREFIJO', 'SV'),
            'timeout' => (int) env('QR_TIMEOUT', 15),
        ],

        'banco' => [
            'url_base' => env('QR_URL_BASE'),
            'token' => env('QR_TOKEN'),
            'secreto_webhook' => env('QR_SECRETO_WEBHOOK'),
            'ruta_generar' => env('QR_RUTA_GENERAR', '/qr'),
            'ruta_consultar' => env('QR_RUTA_CONSULTAR', '/qr/{id}'),
            'timeout' => (int) env('QR_TIMEOUT', 15),
            'cabeceras' => [],
        ],

    ],

];
