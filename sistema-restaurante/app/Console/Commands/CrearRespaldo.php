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
        // Si el volcado falla, queda en la bitácora: sin pantalla de
        // respaldos, es donde se ve que anoche no hubo.
        try {
            $hecho = Respaldos::crear();
        } catch (\Throwable $e) {
            Auditor::registrar('RESPALDO_FALLIDO', null, null, [
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            $this->error('No se pudo hacer el respaldo: '.$e->getMessage());

            return self::FAILURE;
        }

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

        // Sin la copia externa, el respaldo vive en el mismo disco que la base:
        // se pierde con él. Queda en la bitácora y el comando termina con error
        // para que el programador y revisar-salud.sh lo vean.
        if ($hecho['error_copia'] ?? null) {
            Auditor::registrar('RESPALDO_COPIA_FALLIDA', null, null, [
                'archivo' => $nombre,
                'error' => $hecho['error_copia'],
            ]);
            $this->error('No se pudo copiar a la carpeta externa: '.$hecho['error_copia']);
        }

        // Lo mismo con la nube: sin internet o con el permiso de Drive
        // vencido, el respaldo queda solo en el servidor.
        if ($hecho['error_nube'] ?? null) {
            Auditor::registrar('RESPALDO_NUBE_FALLIDA', null, null, [
                'archivo' => $nombre,
                'error' => $hecho['error_nube'],
            ]);
            $this->error('No se pudo subir a la nube: '.$hecho['error_nube']);
        } elseif ($hecho['nube'] ?? null) {
            $this->line('Nube:  subido a '.$hecho['nube']);
        }

        return ($hecho['error_copia'] ?? null) || ($hecho['error_nube'] ?? null) ? self::FAILURE : self::SUCCESS;
    }
}
