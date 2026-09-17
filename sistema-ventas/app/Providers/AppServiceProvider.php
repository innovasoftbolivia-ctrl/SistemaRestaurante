<?php

namespace App\Providers;

use App\Listeners\ComprobarBaseDeDatos;
use App\Support\Config;
use App\Support\Menu;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // @puede('usuarios.gestionar') ... @endpuede
        Blade::if('puede', fn (string $codigo) => Menu::puede($codigo));

        // `@facturacion … @else … @endfacturacion`: lo que solo se ve cuando el
        // negocio factura (config/ventas.php, `mostrar_facturacion`).
        Blade::if('facturacion', fn () => Config::facturacionVisible());

        // Registrado a mano y no por descubrimiento automático: así queda a
        // la vista que `/up` comprueba la base, que es lo que le da sentido
        // al monitoreo (ver el docblock del listener).
        Event::listen(DiagnosingHealth::class, ComprobarBaseDeDatos::class);
    }
}
