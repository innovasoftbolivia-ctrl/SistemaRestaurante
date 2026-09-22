<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\RegistroDeErrores;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El visor de errores del desarrollador: por debajo del sistema. Nadie sin la
 * clave lo ve —ni el administrador, con todos los permisos—, y para ellos ni
 * siquiera existe (404).
 */
class VisorDeErroresTest extends TestCase
{
    use DatabaseTransactions;

    private const CLAVE = 'clave-del-desarrollador-de-prueba-123';

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['restaurante.clave_desarrollador' => self::CLAVE]);

        // Un archivo de log propio, para no depender de lo que haya en storage.
        $this->archivo = storage_path('logs/laravel-2099-01-01.log');
        File::put($this->archivo, implode("\n", [
            '[2099-01-01 10:00:00] production.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /app/Foo.php:10)',
            '[stacktrace]',
            '#0 /app/Bar.php(20): Foo->calcular()',
            '#1 {main}',
            '"} ',
            '[2099-01-01 10:05:00] production.WARNING: El banco no contestó el QR',
            '[2099-01-01 11:00:00] production.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /app/Foo.php:10)',
            '[stacktrace]',
            '#0 /app/Bar.php(21): Foo->calcular()',
            '"} ',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        File::delete($this->archivo);

        parent::tearDown();
    }

    public function test_sin_clave_no_existe_ni_para_el_administrador(): void
    {
        $this->get(route('errores'))->assertNotFound();
        $this->get(route('errores', ['clave' => 'otra-clave-cualquiera-muy-larga']))->assertNotFound();

        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $this->actingAs($admin)->get(route('errores'))->assertNotFound();
    }

    public function test_sin_clave_configurada_el_visor_no_existe(): void
    {
        config(['restaurante.clave_desarrollador' => '']);
        $this->get(route('errores', ['clave' => '']))->assertNotFound();

        // Una clave corta es adivinable: tampoco abre.
        config(['restaurante.clave_desarrollador' => 'corta']);
        $this->get(route('errores', ['clave' => 'corta']))->assertNotFound();
    }

    /** Con la clave, queda una cookie y la dirección se limpia; con la cookie, se ve. */
    public function test_con_la_clave_se_ven_los_errores_juntos(): void
    {
        $entrar = $this->get(route('errores', ['clave' => self::CLAVE, 'archivo' => 'laravel-2099-01-01.log']));
        $entrar->assertRedirect(route('errores', ['archivo' => 'laravel-2099-01-01.log']));
        $entrar->assertCookie('visor_errores');

        $this->withCookie('visor_errores', hash('sha256', self::CLAVE))
            ->get(route('errores', ['archivo' => 'laravel-2099-01-01.log']))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Division by zero')
            ->assertSee('2 veces')
            ->assertSee('El banco no contestó el QR')
            // La traza de la última vez.
            ->assertSee('Bar.php(21)');
    }

    public function test_agrupa_filtra_y_no_sale_de_la_carpeta_de_logs(): void
    {
        $todas = RegistroDeErrores::entradas('laravel-2099-01-01.log');
        $this->assertCount(2, $todas);
        // El error pasó por última vez a las 11:00, el aviso a las 10:05.
        $this->assertSame(['ERROR', 'WARNING'], $todas->pluck('nivel')->all(), 'lo más reciente primero');
        $this->assertSame(2, $todas->firstWhere('nivel', 'ERROR')['veces']);

        $this->assertCount(1, RegistroDeErrores::entradas('laravel-2099-01-01.log', 'WARNING'));

        // Un nombre con ruta no sale de storage/logs.
        $this->assertCount(0, RegistroDeErrores::entradas('../../.env'));
    }
}
