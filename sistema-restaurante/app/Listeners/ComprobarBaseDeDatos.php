<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Hace que `/up` diga la verdad.
 *
 * El endpoint de salud que trae Laravel responde 200 en cuanto el framework
 * arranca, sin tocar la base. Para este sistema eso es casi inútil: la falla
 * que de verdad deja al negocio sin poder vender —MySQL caído, el disco
 * lleno, las credenciales cambiadas— pasaría desapercibida, y cualquier
 * monitoreo colgado de `/up` (el healthcheck de docker-compose, un servicio
 * externo, scripts/revisar-salud.sh) informaría «todo bien» mientras nadie
 * puede cobrar.
 *
 * Si la consulta falla, la excepción sube y `/up` responde 500, que es
 * exactamente lo que un monitoreo necesita ver.
 */
class ComprobarBaseDeDatos
{
    public function handle(DiagnosingHealth $event): void
    {
        // `SELECT 1` y no un conteo sobre una tabla real: alcanza para saber
        // que la conexión está viva y no cuesta nada aunque el monitoreo
        // pregunte cada minuto.
        DB::connection()->select('SELECT 1');
    }
}
