<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el paso si el rol de la cuenta no tiene el permiso indicado.
 *
 *     Route::get(...)->middleware('permiso:usuarios.gestionar');
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return redirect()->route('login');
        }

        foreach ($permisos as $permiso) {
            if ($usuario->tienePermiso($permiso)) {
                return $next($request);
            }
        }

        abort(403, 'Tu rol no tiene permiso para esta acción.');
    }
}
