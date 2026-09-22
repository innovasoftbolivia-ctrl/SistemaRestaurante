<?php

namespace App\Http\Controllers;

use App\Support\Notificaciones;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * La campana de la cabecera, sola: la página la pide cada minuto y reemplaza
 * la que tiene, así un pedido que se demora aparece sin recargar.
 *
 * Devuelve el mismo pedazo de HTML que pinta la cabecera, no JSON: hay una
 * sola plantilla para la campana y no dos (una en Blade, otra en JavaScript)
 * que tarde o temprano dirían cosas distintas. La cabecera `X-Notificaciones`
 * dice que es la campana y no, por ejemplo, la pantalla de login de una sesión
 * vencida, que la página no debe meter en la cabecera.
 */
class NotificacionController extends Controller
{
    public function __invoke(): Response
    {
        return response()
            ->view('components.header._campana', ['avisos' => Notificaciones::para(Auth::user())])
            ->header('X-Notificaciones', '1')
            ->header('Cache-Control', 'no-store');
    }
}
