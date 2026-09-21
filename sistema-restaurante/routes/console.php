<?php

use Illuminate\Support\Facades\Schedule;

// El respaldo de todas las noches. Corre donde haya un programador de tareas
// andando —en Docker de producción, el servicio `programador`—. No tiene
// pantalla: en un hosting sin programador hay que agendar el cron del hosting
// (`php artisan schedule:run` cada minuto) o correr `php artisan
// respaldo:crear` a mano; sin eso no hay respaldos.
Schedule::command('respaldo:crear')->dailyAt('01:00')->withoutOverlapping();

// Los QR vencidos se cancelan en el banco. Si no, un código que el cajero dejó
// atrás sigue siendo cobrable allá durante horas: el cliente paga, el dinero
// entra a la cuenta y no hay ninguna venta esperándolo.
Schedule::command('qr:vencer')->everyFiveMinutes()->withoutOverlapping();
