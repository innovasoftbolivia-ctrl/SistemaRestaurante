<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/up` es de donde cuelga todo el monitoreo: el healthcheck de
 * docker-compose, `scripts/revisar-salud.sh` y cualquier servicio externo.
 * Si responde 200 cuando el sistema en realidad no puede vender, el
 * monitoreo entero deja de servir.
 */
class SaludTest extends TestCase
{
    use DatabaseTransactions;

    public function test_up_responde_bien_con_todo_en_orden(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * La falla que de verdad deja al negocio sin cobrar es la base caída, y
     * el `/up` que trae Laravel no la nota: responde 200 igual, porque solo
     * comprueba que el framework arranque.
     */
    public function test_up_falla_si_la_base_no_responde(): void
    {
        // Se apunta la conexión a un host que no existe y se descarta la ya
        // abierta: es la forma de simular «MySQL no está» sin apagar nada.
        config(['database.connections.'.config('database.default').'.host' => 'no-existe-este-host']);
        DB::purge(config('database.default'));

        $this->get('/up')->assertServerError();
    }
}
