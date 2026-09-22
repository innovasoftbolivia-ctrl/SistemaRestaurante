<?php

namespace App\Http\Controllers;

use App\Support\RegistroDeErrores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El visor de errores del desarrollador, por debajo del sistema.
 *
 * No está en ningún menú ni depende de usuarios o permisos: el administrador
 * del cliente puede darse cualquier permiso, y hasta cambiar la contraseña de
 * otro usuario. Entra solo quien tiene CLAVE_DESARROLLADOR, que vive en el
 * .env del servidor. Para cualquier otro la página no existe: 404, el mismo
 * que una dirección cualquiera.
 *
 * Se entra una vez con /_errores?clave=…; queda una cookie cifrada por 12
 * horas y se redirige sin la clave en la dirección, para que no quede en el
 * historial ni se comparta al copiar el enlace.
 */
class ErroresController extends Controller
{
    private const COOKIE = 'visor_errores';

    private const MINUTOS = 12 * 60;

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $clave = (string) config('restaurante.clave_desarrollador');

        // Sin clave configurada (o una corta, adivinable), el visor no existe.
        abort_if(mb_strlen($clave) < 24, 404);

        $huella = hash('sha256', $clave);

        if ($request->filled('clave')) {
            abort_unless(hash_equals($clave, (string) $request->query('clave')), 404);

            return redirect()->route('errores', $request->only('archivo', 'nivel'))
                ->withCookie(cookie(self::COOKIE, $huella, self::MINUTOS, null, null, null, true, false, 'strict'));
        }

        abort_unless(hash_equals($huella, (string) $request->cookie(self::COOKIE)), 404);

        $archivos = RegistroDeErrores::archivos();
        $archivo = $request->query('archivo', $archivos->first()['archivo'] ?? null);
        $nivel = in_array($request->query('nivel'), RegistroDeErrores::NIVELES, true) ? $request->query('nivel') : null;

        return response()
            ->view('errores.index', [
                'archivos' => $archivos,
                'archivo' => $archivo,
                'nivel' => $nivel,
                'entradas' => $archivo ? RegistroDeErrores::entradas($archivo, $nivel) : collect(),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
