<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * El esquema y los catálogos base viven en docs/sql (los carga docker compose).
 * Aquí solo se ajusta lo que el script SQL no puede resolver: las contraseñas,
 * que necesitan el hasher de la aplicación.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CredencialesSeeder::class);
    }
}
