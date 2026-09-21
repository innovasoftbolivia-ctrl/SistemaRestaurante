<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\CredencialesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lo que recibe un cliente nuevo: una base sin datos de ejemplo, sin
 * contraseñas conocidas, y una cuenta que obliga a poner la propia.
 */
class InstalacionLimpiaTest extends TestCase
{
    use DatabaseTransactions;

    private function sql(string $relativo): string
    {
        return (string) file_get_contents(base_path('../docs/sql/'.$relativo));
    }

    /**
     * Los valores de la primera columna de un INSERT: códigos, claves, nombres.
     *
     * @return array<int, string>
     */
    private function primeraColumna(string $sql, string $tabla, int $columna = 0): array
    {
        preg_match('/INSERT INTO '.$tabla.'\s*\([^)]*\)\s*VALUES(.+?);/s', $sql, $bloque);
        $this->assertNotEmpty($bloque, "no encuentro el INSERT de {$tabla}");
        preg_match_all('/^\s*\((.+?)\),?\s*$/m', $bloque[1], $filas);

        $valores = array_map(function (string $fila) use ($columna) {
            preg_match_all("/'((?:[^']|'')*)'|(\\d+)/", $fila, $partes);

            return (string) ($partes[1][$columna] !== '' ? $partes[1][$columna] : $partes[2][$columna]);
        }, $filas[1]);
        sort($valores);

        return $valores;
    }

    // ================================================================ la base

    /** Si se agrega un permiso o una clave de configuración, van a los dos archivos. */
    public function test_la_base_de_produccion_no_se_queda_atras_de_la_de_desarrollo(): void
    {
        $desarrollo = $this->sql('02_datos_iniciales.sql');
        $produccion = $this->sql('produccion/02_datos_base.sql');

        foreach (['permisos', 'roles', 'metodos_pago', 'tipos_comprobante', 'configuracion'] as $tabla) {
            $columna = in_array($tabla, ['roles', 'metodos_pago', 'tipos_comprobante'], true) ? 1 : 0;
            $this->assertSame(
                $this->primeraColumna($desarrollo, $tabla, $columna),
                $this->primeraColumna($produccion, $tabla, $columna),
                "«{$tabla}» no coincide entre 02_datos_iniciales.sql y produccion/02_datos_base.sql",
            );
        }

        // Y los permisos de cada rol, línea por línea.
        preg_match_all('/WHERE codigo IN \\(([^)]+)\\)/', $desarrollo, $rolesDesarrollo);
        preg_match_all('/WHERE codigo IN \\(([^)]+)\\)/', $produccion, $rolesProduccion);
        $this->assertSame($rolesDesarrollo[1], $rolesProduccion[1]);
    }

    public function test_la_base_de_produccion_no_trae_datos_de_ejemplo_ni_contrasenas(): void
    {
        $produccion = $this->sql('produccion/02_datos_base.sql');

        foreach (['productos', 'clientes', 'ventas'] as $tabla) {
            $this->assertDoesNotMatchRegularExpression('/INSERT INTO '.$tabla.'\b/', $produccion, "trae {$tabla} de ejemplo");
        }

        $this->assertStringNotContainsString('cajero1', $produccion);
        $this->assertStringNotContainsString('admin123', $produccion);
        $this->assertMatchesRegularExpression('/\'admin\',\s*\'\$2y\$10\$abcdefghij[^\']+\',\s*1\)/', $produccion,
            'el admin tiene que arrancar con un hash inservible y el cambio obligatorio');
    }

    public function test_docker_de_produccion_carga_la_base_limpia(): void
    {
        $compose = (string) file_get_contents(base_path('../docker-compose.prod.yml'));

        $this->assertStringContainsString('./docs/sql/produccion/02_datos_base.sql:/docker-entrypoint-initdb.d/02_datos_base.sql', $compose);
        $this->assertStringNotContainsString('./docs/sql:/docker-entrypoint-initdb.d', $compose);
    }

    // ================================================================ primer acceso

    public function test_en_produccion_el_admin_arranca_con_contrasena_aleatoria_y_cambio_obligatorio(): void
    {
        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $admin->forceFill(['password_actualizado_en' => null])->save();
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => CredencialesSeeder::class, '--force' => true])
            ->expectsOutputToContain('PRIMER ACCESO')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertFalse(Hash::check('admin123', $admin->password_hash));
        $this->assertTrue($admin->debe_cambiar_password);
        $this->assertSame($cajero->password_hash, $cajero->fresh()->password_hash, 'no toca otras cuentas');

        // El segundo arranque no la vuelve a pisar.
        $hash = $admin->password_hash;
        $this->artisan('db:seed', ['--class' => CredencialesSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertSame($hash, $admin->fresh()->password_hash);
    }

    // ================================================================ cambio obligatorio

    public function test_con_el_cambio_pendiente_solo_se_llega_al_perfil_hasta_cambiarla(): void
    {
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $cajero->forceFill(['password_hash' => Hash::make('puesta-por-otro'), 'debe_cambiar_password' => true])->save();

        $this->actingAs($cajero)->get('/pos')
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('aviso');
        $this->actingAs($cajero)->getJson('/pos/productos')->assertForbidden();
        $this->actingAs($cajero)->get('/perfil')->assertOk();

        $this->actingAs($cajero)->put('/perfil/password', [
            'password_actual' => 'puesta-por-otro',
            'password' => 'puesta-por-otro',
            'password_confirmation' => 'puesta-por-otro',
        ])->assertSessionHasErrors('password');

        $this->actingAs($cajero)->put('/perfil/password', [
            'password_actual' => 'puesta-por-otro',
            'password' => 'mi-clave-propia-2026',
            'password_confirmation' => 'mi-clave-propia-2026',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($cajero->fresh()->debe_cambiar_password);
        $this->actingAs($cajero->fresh())->get('/pos')->assertOk();
    }

    /** La contraseña que restablece un administrador la cambia la persona al entrar. */
    public function test_la_contrasena_restablecida_por_el_administrador_se_cambia_al_entrar(): void
    {
        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();

        $this->actingAs($admin)->put(route('usuarios.update', $cajero), [
            'rol_id' => $cajero->rol_id,
            'usuario' => $cajero->usuario,
            'password' => 'temporal-1234',
            'password_confirmation' => 'temporal-1234',
            'activo' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($cajero->fresh()->debe_cambiar_password);

        // La propia, desde la ficha de usuarios, no —y exige la contraseña actual.
        $admin->forceFill(['password_hash' => Hash::make('clave-de-hoy-2026')])->save();

        $this->actingAs($admin->fresh())->put(route('usuarios.update', $admin), [
            'rol_id' => $admin->rol_id,
            'usuario' => $admin->usuario,
            'password_actual' => 'clave-de-hoy-2026',
            'password' => 'otra-clave-5678',
            'password_confirmation' => 'otra-clave-5678',
            'activo' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($admin->fresh()->debe_cambiar_password);
    }
}
