<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Usuario;
use Database\Seeders\CredencialesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use DatabaseTransactions;

    private function cuentaAdmin(string $clave = 'admin123'): Usuario
    {
        $cuenta = Usuario::where('usuario', 'admin')->firstOrFail();
        $cuenta->forceFill(['password_hash' => Hash::make($clave), 'activo' => 1])->save();

        return $cuenta;
    }

    public function test_la_pantalla_de_login_se_muestra(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_una_cuenta_valida_ingresa_y_queda_registrada(): void
    {
        $cuenta = $this->cuentaAdmin();

        $respuesta = $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ]);

        $respuesta->assertRedirect('/inicio');
        $this->assertAuthenticatedAs($cuenta->fresh());

        $this->assertNotNull($cuenta->fresh()->ultimo_acceso);
        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $cuenta->id,
            'accion' => 'LOGIN',
        ]);
    }

    public function test_una_contrasena_incorrecta_no_ingresa_y_suma_un_intento_fallido(): void
    {
        $cuenta = $this->cuentaAdmin();
        $cuenta->forceFill(['intentos_fallidos' => 0])->save();

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'no-es-la-clave',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
        $this->assertSame(1, $cuenta->fresh()->intentos_fallidos);
    }

    public function test_una_cuenta_desactivada_no_ingresa_aunque_la_clave_sea_correcta(): void
    {
        $cuenta = $this->cuentaAdmin();
        $cuenta->forceFill(['activo' => 0])->save();

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    public function test_un_empleado_cesado_pierde_el_acceso(): void
    {
        $cuenta = $this->cuentaAdmin();

        // El trigger de la base desactiva la cuenta al cesar al empleado.
        $cuenta->empleado->update([
            'estado' => 'CESADO',
            'fecha_cese' => now()->toDateString(),
            'motivo_cese' => 'prueba',
        ]);

        $this->assertFalse((bool) $cuenta->fresh()->activo);

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    public function test_la_sesion_se_corta_si_la_cuenta_se_deshabilita_mientras_navega(): void
    {
        $cuenta = $this->cuentaAdmin();

        $this->actingAs($cuenta)->get('/empleados')->assertOk();

        $cuenta->forceFill(['activo' => 0])->save();

        $this->actingAs($cuenta->fresh())
            ->get('/empleados')
            ->assertRedirect('/login');
    }

    public function test_cerrar_sesion_deja_al_usuario_fuera(): void
    {
        $cuenta = $this->cuentaAdmin();

        $this->actingAs($cuenta)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * `/logout` solo responde a POST. Al escribir esa dirección en el
     * navegador se llega a un 405, y sin la vista `errors/405` eso caía en la
     * página por defecto de Symfony: en inglés y con aspecto de sistema roto.
     */
    public function test_abrir_una_direccion_de_solo_post_muestra_la_pagina_del_sistema(): void
    {
        $respuesta = $this->actingAs($this->cuentaAdmin())->get('/logout');

        $respuesta->assertStatus(405);
        $respuesta->assertSee('Esa dirección no se abre así');
        $respuesta->assertDontSee('An Error Occurred');
    }

    public function test_un_visitante_no_entra_a_las_pantallas_internas(): void
    {
        $this->get('/empleados')->assertRedirect('/login');
    }

    public function test_el_empleado_sin_cuenta_existe_pero_no_puede_ingresar(): void
    {
        $jorge = Empleado::where('documento', '10000004')->firstOrFail();

        $this->assertNull($jorge->usuario);
        $this->assertSame('ACTIVO', $jorge->estado);
        $this->assertFalse(Auth::attempt(['usuario' => 'jorge', 'password' => 'lo-que-sea']));
    }

    // ------------------------------------------------- CredencialesSeeder

    /**
     * El seeder deja un primer acceso funcionando cuando todavía no hay
     * contraseña real puesta (el volcado de docs/sql deja un hash de
     * ejemplo, sin `password_actualizado_en`).
     */
    public function test_el_seeder_pone_la_contrasena_de_fabrica_en_una_cuenta_nueva(): void
    {
        $cuenta = Usuario::where('usuario', 'admin')->firstOrFail();
        $cuenta->forceFill(['password_hash' => 'hash-de-ejemplo-no-usable', 'password_actualizado_en' => null])->save();

        $this->seed(CredencialesSeeder::class);

        $this->assertTrue(Hash::check('admin123', $cuenta->fresh()->password_hash));
        $this->assertNotNull($cuenta->fresh()->password_actualizado_en);
    }

    /**
     * El `entrypoint` de Docker corre este seeder en cada arranque del
     * contenedor, no solo la primera vez. Si el negocio ya cambió su
     * contraseña, un reinicio del servidor no debe devolvérsela a la de
     * fábrica.
     */
    public function test_el_seeder_no_pisa_una_contrasena_que_ya_se_cambio(): void
    {
        $cuenta = $this->cuentaAdmin('la-clave-real-del-negocio');
        // Lo que deja cualquier cambio de contraseña real (PerfilController,
        // UsuarioController): es justo lo que el seeder mira para no tocarla.
        $cuenta->forceFill(['password_actualizado_en' => now()])->save();

        $this->seed(CredencialesSeeder::class);

        $this->assertTrue(Hash::check('la-clave-real-del-negocio', $cuenta->fresh()->password_hash));
        $this->assertFalse(Hash::check('admin123', $cuenta->fresh()->password_hash));
    }

    // ================================================================ freno por origen

    /**
     * El freno por cuenta ya existía; lo que faltaba era uno por origen: probar
     * UNA contraseña contra todas las cuentas del negocio no gastaba cupo en
     * ninguna.
     */
    public function test_muchos_intentos_desde_el_mismo_origen_se_frenan(): void
    {
        $bloqueado = false;

        for ($i = 0; $i < 25; $i++) {
            $respuesta = $this->post(route('login.store'), [
                'usuario' => 'cuenta'.$i,
                'password' => 'la-de-siempre',
            ]);

            if ($respuesta->getStatusCode() === 429) {
                $bloqueado = true;
                break;
            }
        }

        $this->assertTrue($bloqueado, 'Se pudo probar una contraseña en 25 cuentas distintas sin ningún freno.');
    }
}
