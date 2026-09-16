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

// Los QR vencidos se cancelan en el banco. Si no, un código que el cajero dejó
// atrás sigue siendo cobrable allá durante horas: el cliente paga, el dinero
// entra a la cuenta y no hay ninguna venta esperándolo.
Schedule::command('qr:vencer')->everyFiveMinutes()->withoutOverlapping();
