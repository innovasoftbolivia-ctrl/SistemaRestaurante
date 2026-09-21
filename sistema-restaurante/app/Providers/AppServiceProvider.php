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
        // negocio factura (config/restaurante.php, `mostrar_facturacion`).
        Blade::if('facturacion', fn () => Config::facturacionVisible());

        // El número de envío único de un formulario (ver UnSoloEnvio).
        Blade::directive('unEnvio', fn () => '<input type="hidden" name="_envio" value="<?php echo e(\Illuminate\Support\Str::uuid()); ?>">');

        // Registrado a mano y no por descubrimiento automático: así queda a
        // la vista que `/up` comprueba la base, que es lo que le da sentido
        // al monitoreo (ver el docblock del listener).
        Event::listen(DiagnosingHealth::class, ComprobarBaseDeDatos::class);
    }
}
