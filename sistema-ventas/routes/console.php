<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El respaldo de todas las noches. Corre donde haya un programador de tareas
// andando —en Docker de producción, el servicio `programador`—. En un hosting
// compartido no lo hay, y los respaldos se hacen desde Sistema > Respaldos.
Schedule::command('respaldo:crear')->dailyAt('01:00')->withoutOverlapping();
