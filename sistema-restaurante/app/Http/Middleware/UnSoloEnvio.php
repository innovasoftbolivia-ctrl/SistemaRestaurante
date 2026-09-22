<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un formulario se registra una sola vez.
 *
 * Cada formulario que mueve dinero o carga platos lleva un número de envío
 * único (`@unEnvio`). Un doble clic o un reenvío del navegador manda dos veces
 * el mismo número: el segundo no se procesa. Sin esto, dos clics rápidos en
 * «Cobrar» dejaban dos ventas, y en «Agregar al pedido», el plato dos veces
 * en la cocina.
 *
 * Si la operación no llegó a hacerse (error de validación, del servicio o un
 * error inesperado del servidor), el número se libera para que el mismo
 * formulario pueda corregirse y reenviarse.
 */
class UnSoloEnvio
{
    public function handle(Request $request, Closure $next): Response
    {
        $numero = (string) $request->input('_envio', '');

        // Obligatorio: todos los formularios lo traen (`@unEnvio`). Sin él, una
        // petición armada a mano podía repetir una venta sin que nada la frenara.
        if ($numero === '') {
            return back()->with('error', 'El formulario llegó incompleto. Recarga la página y vuelve a intentarlo.');
        }

        $clave = 'envio:'.($request->user()?->id ?? 'anonimo').':'.sha1($numero);

        if (! Cache::add($clave, true, now()->addHour())) {
            // Puede que el primer envío todavía se esté procesando: no se
            // afirma que quedó registrado, se manda a comprobarlo.
            return back()->with('aviso', 'Esa operación ya se envió y no se repitió. Antes de volver a intentarla, revisa si quedó registrada.');
        }

        $respuesta = $next($request);

        $fallo = $respuesta->getStatusCode() >= 500
            || ($request->hasSession() && ($request->session()->has('errors') || $request->session()->has('error')));

        if ($fallo) {
            Cache::forget($clave);
        }

        return $respuesta;
    }
}
