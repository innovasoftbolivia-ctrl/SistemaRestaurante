<?php

namespace App\Console\Commands;

use App\Services\Auditor;
use App\Services\Respaldos;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * El respaldo de todas las noches. Lo dispara el programador de tareas
 * (routes/console.php); también se puede correr a mano.
 */
class CrearRespaldo extends Command
{
    protected $signature = 'respaldo:crear';

    protected $description = 'Guarda un respaldo de la base y de las fotos, y borra los de más de '.Respaldos::DIAS.' días';

    public function handle(): int
    {
        $hecho = Respaldos::crear();
        $nombre = basename($hecho['base']);

        Auditor::registrar('RESPALDO_CREADO', null, null, [
            'archivo' => $nombre,
            'fotos' => $hecho['fotos'] ? basename($hecho['fotos']) : null,
            'origen' => 'programado',
        ]);

        $this->info("Base:  {$nombre} (".Number::fileSize((int) filesize($hecho['base'])).')');
        $this->line($hecho['fotos']
            ? 'Fotos: '.basename($hecho['fotos'])
            : 'Fotos: no había fotos que guardar.');

        return self::SUCCESS;
    }
}
