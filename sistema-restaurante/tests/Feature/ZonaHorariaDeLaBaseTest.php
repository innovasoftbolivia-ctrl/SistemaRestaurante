<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La base y la aplicación tienen que marcar la misma hora: los procedimientos
 * usan NOW() y el modo PHP usa now().
 *
 * El MySQL de desarrollo ya arranca en -04:00 (docker-compose), así que mirar
 * solo la hora no probaría nada: un hosting con MySQL en UTC es justo el caso.
 * Por eso se comprueba también que cada conexión fija su propia zona.
 */
class ZonaHorariaDeLaBaseTest extends TestCase
{
    public function test_cada_conexion_fija_la_hora_de_bolivia(): void
    {
        $this->assertSame('-04:00', config('database.connections.mysql.timezone'));

        // Con otra zona configurada, la conexión la aplica: no depende de la del servidor.
        config(['database.connections.mysql.timezone' => '+00:00']);
        DB::purge('mysql');
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS zona')->zona);

        config(['database.connections.mysql.timezone' => '-04:00']);
        DB::purge('mysql');
        $this->assertSame('-04:00', DB::selectOne('SELECT @@session.time_zone AS zona')->zona);

        $ahoraEnLaBase = Carbon::parse(DB::selectOne('SELECT NOW() AS ahora')->ahora);
        $this->assertLessThan(120, abs($ahoraEnLaBase->diffInSeconds(now())));
    }
}
