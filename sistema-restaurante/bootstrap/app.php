<?php

use App\Http\Middleware\ExigirCambioDePassword;
use App\Http\Middleware\UnSoloEnvio;
use App\Http\Middleware\VerificarCuentaVigente;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'cuenta.vigente' => VerificarCuentaVigente::class,
            'password.propia' => ExigirCambioDePassword::class,
            'un.envio' => UnSoloEnvio::class,
        ]);

        // Vacío por omisión: sin proxy delante, no hay nada que confiar. Si el
        // servidor real queda detrás de uno (ver README, "Nueva instalación",
        // el paso de HTTPS), TRUSTED_PROXIES trae su IP —o "*" si es de
        // confianza total, como un balanceador en la misma red privada—.
        // Sin esto detrás de un proxy: la IP que queda en la bitácora es la
        // del proxy, no la del cliente, y las URLs que arma Laravel salen en
        // http:// aunque el visitante haya entrado por https://.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))));

        // El aviso de pago del banco no puede traer token CSRF: quien llama es
        // el banco, no un formulario del sistema. Su defensa es la firma del
        // aviso, no la sesión.
        $middleware->validateCsrfTokens(except: ['qr/aviso', 'api/qrsimple/notifyPaymentQR']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
