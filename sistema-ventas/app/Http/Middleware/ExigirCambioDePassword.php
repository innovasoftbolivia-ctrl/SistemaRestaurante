<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la contraseña la puso otro —la instalación o un administrador que la
 * restableció—, lo primero al entrar es cambiarla. Hasta entonces solo se
 * llega al perfil.
 *
 * Una contraseña que alguien más conoce no es de quien la usa: no sirve para
 * saber quién anuló una venta o cerró una caja.
 */
class ExigirCambioDePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario?->debe_cambiar_password || $request->routeIs('perfil.edit', 'perfil.password')) {
            return $next($request);
        }

        $mensaje = 'Antes de seguir, cambia tu contraseña: la que tienes la puso otra persona.';

        if ($request->expectsJson()) {
            return response()->json(['error' => $mensaje], 403);
        }

        return redirect()->route('perfil.edit')->with('aviso', $mensaje);
    }
}
