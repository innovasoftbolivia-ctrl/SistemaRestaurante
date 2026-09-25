<?php

namespace Tests\Feature;

use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Qué se ve cuando la base de datos no contesta.
 *
 * Pasa de verdad —en el hosting gratuito la base se cae sola cada tanto—, y
 * antes tumbaba la propia pantalla de acceso con un error 500: la dibuja
 * `Config::negocio()`, que consulta la tabla `configuracion`. Ahora la pantalla
 * se ve igual y el aviso sale en el formulario, no en una pantalla de error.
 *
 * La caída se simula con una conexión a un puerto cerrado: es la falla real
 * —el motor no responde—, no un doble de prueba.
 */
class SinBaseDeDatosTest extends TestCase
{
    use DatabaseTransactions;

    /** Corre $prueba con la base caída, y deja la conexión buena al terminar. */
    private function conLaBaseCaida(callable $prueba): void
    {
        config([
            'database.connections.sin_base' => array_merge(
                config('database.connections.mysql'),
                // El puerto 1 no escucha: el rechazo es inmediato y la prueba
                // no se queda esperando a que venza el tiempo de conexión.
                ['host' => '127.0.0.1', 'port' => 1],
            ),
        ]);

        $buena = DB::getDefaultConnection();
        DB::setDefaultConnection('sin_base');
        Config::olvidar();

        try {
            $prueba();
        } finally {
            DB::setDefaultConnection($buena);
            DB::purge('sin_base');
            Config::olvidar();
        }
    }

    public function test_la_pantalla_de_acceso_se_ve_aunque_la_base_no_conteste(): void
    {
        config(['app.name' => 'Nombre de respaldo']);
        Log::spy();

        $this->conLaBaseCaida(function () {
            $respuesta = $this->get('/login');

            $respuesta->assertOk();
            // Sin la tabla `configuracion` el nombre sale del .env, y el
            // formulario se puede usar igual.
            $respuesta->assertSee('Nombre de respaldo');
            $respuesta->assertSee('Ingresar');
        });

        // La falla no se esconde: queda en el registro que ve el desarrollador.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje) => str_contains($mensaje, 'No se pudo leer la configuración del negocio'))
            ->atLeast()->once();
    }

    public function test_entrar_con_la_base_caida_avisa_en_el_formulario(): void
    {
        $this->conLaBaseCaida(function () {
            $respuesta = $this->from('/login')->post('/login', [
                'usuario' => 'admin',
                'password' => 'admin2026',
            ]);

            $respuesta->assertRedirect('/login');
            $respuesta->assertSessionHasErrors('usuario');
            $this->assertStringContainsString(
                'no logra conectarse a su base de datos',
                (string) session('errors')->first('usuario'),
            );
            // Y nadie queda dentro por una caída del servidor.
            $this->assertGuest();
        });
    }

    /** La configuración vacía no inventa valores: cada uno cae en el suyo. */
    public function test_los_parametros_caen_en_su_valor_por_omision(): void
    {
        $this->conLaBaseCaida(function () {
            $this->assertSame(config('app.name'), Config::negocio());
            $this->assertSame(0.0, Config::tasaImpuesto());
            $this->assertFalse(Config::preciosIncluyenImpuesto());
        });
    }
}
