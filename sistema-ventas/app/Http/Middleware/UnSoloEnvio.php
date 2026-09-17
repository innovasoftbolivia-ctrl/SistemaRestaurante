<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un formulario se registra una sola vez.
 *
 * Cada formulario que mueve stock o dinero lleva un número de envío único
 * (`@unEnvio`). Un doble clic o un reenvío del navegador manda dos veces el
 * mismo número: el segundo no se procesa. Antes, dos clics rápidos en
 * «Registrar compra» dejaban dos compras y el stock al doble.
 *
 * Si la operación no llegó a hacerse (error de validación o del servicio), el
 * número se libera para que el mismo formulario pueda corregirse y reenviarse.
 */
class UnSoloEnvio
{
    public function handle(Request $request, Closure $next): Response
    {
        $numero = (string) $request->input('_envio', '');

        if ($numero === '') {
            return $next($request);
        }

        $clave = 'envio:'.($request->user()?->id ?? 'anonimo').':'.sha1($numero);

        if (! Cache::add($clave, true, now()->addHour())) {
            return back()->with('aviso', 'Esa operación ya se había enviado: se registró una sola vez.');
        }

        $respuesta = $next($request);

        if ($request->hasSession() && ($request->session()->has('errors') || $request->session()->has('error'))) {
            Cache::forget($clave);
        }

        return $respuesta;
    }
}
