<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * El script docs/sql/02_datos_iniciales.sql deja un hash de ejemplo que no
 * corresponde a ninguna contraseña real. Este seeder pone contraseñas
 * utilizables para el entorno de desarrollo, y también es lo que deja una
 * instalación real recién levantada con un primer acceso funcionando.
 *
 * El `entrypoint` de Docker lo corre en CADA arranque del contenedor —no solo
 * la primera vez—, así que solo toca cuentas que nunca tuvieron una
 * contraseña real puesta (`password_actualizado_en IS NULL`). Sin esta
 * condición, reiniciar el contenedor —un reinicio del servidor, una
 * actualización— pisaba en silencio la contraseña que el negocio ya hubiera
 * cambiado, dejando la cuenta otra vez con la clave de fábrica.
 *
 *     php artisan db:seed --class=CredencialesSeeder
 */
class CredencialesSeeder extends Seeder
{
    /** usuario => contraseña de desarrollo */
    private const CLAVES = [
        'admin' => 'admin123',
        'cajero1' => 'cajero123',
        'almacen' => 'almacen123',
    ];

    public function run(): void
    {
        // En producción no hay contraseñas de fábrica: ver primerAccesoDeProduccion().
        if (app()->environment('production')) {
            $this->primerAccesoDeProduccion();

            return;
        }

        foreach (self::CLAVES as $usuario => $clave) {
            $cuenta = Usuario::where('usuario', $usuario)
                ->whereNull('password_actualizado_en')
                ->first();

            if (! $cuenta) {
                $this->command->warn("«{$usuario}» no existe o ya tiene una contraseña propia; se omite.");

                continue;
            }

            $cuenta->forceFill([
                'password_hash' => Hash::make($clave),
                'password_actualizado_en' => now(),
                'intentos_fallidos' => 0,
            ])->save();

            $this->command->info("Contraseña establecida para «{$usuario}».");
        }
    }

    /**
     * Una instalación real no arranca con admin/admin123, que está publicada
     * en el README. Se genera una contraseña aleatoria para `admin`, se muestra
     * UNA vez en el log del arranque y se marca el cambio obligatorio: quien
     * entra primero pone la suya. Las cuentas de prueba no existen en la base
     * de producción (docs/sql/produccion/02_datos_base.sql).
     */
    private function primerAccesoDeProduccion(): void
    {
        $cuenta = Usuario::where('usuario', 'admin')->whereNull('password_actualizado_en')->first();

        if (! $cuenta) {
            $this->command->info('El primer acceso ya estaba configurado.');

            return;
        }

        $clave = Str::password(14, symbols: false);

        $cuenta->forceFill([
            'password_hash' => Hash::make($clave),
            'password_actualizado_en' => now(),
            'debe_cambiar_password' => true,
            'intentos_fallidos' => 0,
        ])->save();

        $this->command->warn(str_repeat('=', 64));
        $this->command->warn('  PRIMER ACCESO — usuario: admin   contraseña: '.$clave);
        $this->command->warn('  Se muestra una sola vez. Al entrar, el sistema pide cambiarla.');
        $this->command->warn(str_repeat('=', 64));
    }
}
