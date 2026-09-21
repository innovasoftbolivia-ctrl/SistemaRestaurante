<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta puede quedar inhabilitada mientras la sesión sigue abierta
 * (por ejemplo si se cesa al empleado). Aquí se corta la sesión en ese caso.
 */
class VerificarCuentaVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario && ! $usuario->loadMissing('empleado')->puedeIngresar()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['usuario' => 'Tu cuenta fue deshabilitada. Comunícate con el administrador.']);
        }

        return $next($request);
    }
}
