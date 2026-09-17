<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalYAccesosTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    // ------------------------------------------------------------------ roles

    public function test_el_rol_cajero_no_entra_a_la_gestion_de_usuarios(): void
    {
        $this->actingAs($this->cajero())->get('/usuarios')->assertForbidden();
        $this->actingAs($this->cajero())->get('/empleados')->assertForbidden();
    }

    public function test_el_administrador_entra_a_todos_los_modulos(): void
    {
        $rutas = [
            '/empleados', '/empleados/nuevo', '/cargos',
            '/usuarios', '/usuarios/nuevo', '/roles', '/perfil',
        ];

        foreach ($rutas as $ruta) {
            $this->actingAs($this->admin())->get($ruta)->assertOk();
        }

        $empleado = Empleado::first();
        $this->actingAs($this->admin())->get("/empleados/{$empleado->id}")->assertOk();
        $this->actingAs($this->admin())->get("/empleados/{$empleado->id}/editar")->assertOk();
        $this->actingAs($this->admin())->get("/usuarios/{$this->admin()->id}/editar")->assertOk();
    }

    /**
     * El menú lateral se arma con los permisos del rol, así que el cajero no
     * debe ver siquiera los enlaces de los módulos que no puede usar.
     */
    public function test_el_menu_lateral_solo_muestra_lo_que_el_rol_permite(): void
    {
        $respuesta = $this->actingAs($this->cajero())->get('/perfil')->assertOk();

        $respuesta->assertDontSee('href="'.url('/usuarios').'"', false);
        $respuesta->assertDontSee('href="'.url('/empleados').'"', false);
        $respuesta->assertSee('href="'.url('/perfil').'"', false);

        $this->actingAs($this->admin())
            ->get('/perfil')
            ->assertSee('href="'.url('/usuarios').'"', false)
            ->assertSee('href="'.url('/empleados').'"', false);
    }

    /**
     * Cada rol entra a su pantalla de trabajo: quien lleva la gestión, a la
     * portada; el cajero, directo al mostrador, porque un clic de más en cada
     * venta se nota.
     */
    public function test_la_raiz_lleva_a_la_pantalla_de_trabajo_de_cada_rol(): void
    {
        $almacenero = Usuario::where('usuario', 'almacen')->firstOrFail();

        $this->actingAs($this->admin())->get('/')->assertRedirect('/inicio');
        // Sin reportes, el almacenero entra directo a su catálogo.
        $this->actingAs($almacenero)->get('/')->assertRedirect('/productos');
        $this->actingAs($this->cajero())->get('/')->assertRedirect('/pos');
    }

    public function test_un_rol_puede_cambiar_sus_permisos(): void
    {
        $rol = Rol::where('nombre', 'Cajero')->firstOrFail();
        $permisos = Permiso::whereIn('codigo', ['ventas.registrar', 'reportes.ver'])
            ->pluck('id')->all();

        $this->actingAs($this->admin())->put("/roles/{$rol->id}", [
            'nombre' => 'Cajero',
            'descripcion' => 'Registra ventas y consulta reportes',
            'activo' => true,
            'permisos' => $permisos,
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['ventas.registrar', 'reportes.ver'],
            $rol->fresh()->permisos->pluck('codigo')->all(),
        );
    }

    public function test_se_crea_un_rol_con_sus_permisos(): void
    {
        $permisos = Permiso::whereIn('codigo', ['ventas.registrar', 'reportes.ver'])
            ->pluck('id')->all();

        $this->actingAs($this->admin())->post('/roles', [
            'nombre' => 'Supervisor de turno',
            'descripcion' => 'Ventas y reportes, sin gestión de personal',
            'permisos' => $permisos,
        ])->assertRedirect(route('roles.index'));

        $rol = Rol::where('nombre', 'Supervisor de turno')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['ventas.registrar', 'reportes.ver'],
            $rol->permisos->pluck('codigo')->all(),
        );
    }

    public function test_no_se_repite_el_nombre_de_un_rol(): void
    {
        $this->actingAs($this->admin())->post('/roles', ['nombre' => 'Cajero'])
            ->assertSessionHasErrors('nombre');
    }

    public function test_un_rol_sin_cuentas_se_elimina(): void
    {
        $rol = Rol::create(['nombre' => 'Rol de prueba temporal', 'activo' => true]);

        $this->actingAs($this->admin())->delete("/roles/{$rol->id}")
            ->assertRedirect(route('roles.index'));

        $this->assertModelMissing($rol);
    }

    public function test_un_rol_con_cuentas_se_desactiva_en_vez_de_borrarse(): void
    {
        $rol = Rol::where('nombre', 'Cajero')->firstOrFail();
        $this->assertTrue($rol->usuarios()->exists());

        $this->actingAs($this->admin())->delete("/roles/{$rol->id}")
            ->assertRedirect(route('roles.index'));

        $this->assertModelExists($rol);
        $this->assertFalse($rol->fresh()->activo);
    }

    // ----------------------------------------------------------------- cargos

    public function test_se_crea_un_cargo(): void
    {
        $this->actingAs($this->admin())->post('/cargos', [
            'nombre' => 'Supervisor de turno',
            'descripcion' => 'Coordina al personal de la tienda',
            'activo' => true,
        ])->assertRedirect('/cargos');

        $this->assertDatabaseHas('cargos', ['nombre' => 'Supervisor de turno']);
    }

    public function test_no_se_repite_el_nombre_de_un_cargo(): void
    {
        $this->actingAs($this->admin())->post('/cargos', [
            'nombre' => 'Cajero', // ya existe
        ])->assertSessionHasErrors('nombre');
    }

    public function test_un_cargo_con_empleados_se_desactiva_en_vez_de_borrarse(): void
    {
        $cargo = Cargo::where('nombre', 'Cajero')->firstOrFail();

        $this->actingAs($this->admin())->delete("/cargos/{$cargo->id}")->assertRedirect('/cargos');

        $this->assertDatabaseHas('cargos', ['id' => $cargo->id, 'activo' => 0]);
    }

    public function test_un_cargo_sin_empleados_si_se_elimina(): void
    {
        $cargo = Cargo::create(['nombre' => 'Cargo de prueba', 'activo' => true]);

        $this->actingAs($this->admin())->delete("/cargos/{$cargo->id}")->assertRedirect('/cargos');

        $this->assertDatabaseMissing('cargos', ['id' => $cargo->id]);
    }

    // -------------------------------------------------------------- empleados

    public function test_se_registra_un_empleado(): void
    {
        $cargo = Cargo::where('nombre', 'Cajero')->firstOrFail();

        $this->actingAs($this->admin())->post('/empleados', [
            'cargo_id' => $cargo->id,
            'tipo_documento' => 'CI',
            'documento' => '99887766',
            'nombres' => 'Rocío',
            'apellidos' => 'Huamán Peña',
            'fecha_ingreso' => '2026-08-01',
            'tipo_contrato' => 'PLAZO_FIJO',
            'estado' => 'ACTIVO',
            'email' => 'rocio@tienda.com',
        ])->assertRedirect('/empleados');

        $empleado = Empleado::where('documento', '99887766')->first();

        $this->assertNotNull($empleado);
        // La columna generada por MySQL arma el nombre completo.
        $this->assertSame('Rocío Huamán Peña', $empleado->nombre_completo);
    }

    public function test_no_se_repite_el_documento_de_un_empleado(): void
    {
        $cargo = Cargo::first();

        $this->actingAs($this->admin())->post('/empleados', [
            'cargo_id' => $cargo->id,
            'tipo_documento' => 'CI',
            'documento' => '10000001', // ya es de Ana
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'fecha_ingreso' => '2026-08-01',
            'tipo_contrato' => 'INDEFINIDO',
            'estado' => 'ACTIVO',
        ])->assertSessionHasErrors('documento');
    }

    public function test_el_cese_conserva_al_empleado_y_le_quita_el_acceso(): void
    {
        $empleado = Empleado::where('documento', '10000002')->firstOrFail(); // Luis, cajero

        $this->actingAs($this->admin())->delete("/empleados/{$empleado->id}", [
            'fecha_cese' => '2026-08-15',
            'motivo_cese' => 'Renuncia voluntaria',
        ])->assertRedirect();

        $empleado->refresh();

        $this->assertSame('CESADO', $empleado->estado);
        $this->assertNotNull($empleado->fecha_cese);
        $this->assertFalse((bool) $empleado->usuario->fresh()->activo);
    }

    public function test_no_se_acepta_una_fecha_de_cese_anterior_al_ingreso(): void
    {
        $empleado = Empleado::where('documento', '10000002')->firstOrFail();

        $this->actingAs($this->admin())->delete("/empleados/{$empleado->id}", [
            'fecha_cese' => '2020-01-01',
            'motivo_cese' => 'Fecha imposible',
        ])->assertSessionHasErrors('fecha_cese');

        $this->assertSame('ACTIVO', $empleado->fresh()->estado);
    }

    // --------------------------------------------------------------- usuarios

    public function test_se_crea_una_cuenta_para_un_empleado_sin_usuario(): void
    {
        $jorge = Empleado::where('documento', '10000004')->firstOrFail();
        $rol = Rol::where('nombre', 'Cajero')->firstOrFail();

        $this->actingAs($this->admin())->post('/usuarios', [
            'empleado_id' => $jorge->id,
            'rol_id' => $rol->id,
            'usuario' => 'jorge.c',
            'password' => 'clave-segura-1',
            'password_confirmation' => 'clave-segura-1',
            'activo' => true,
        ])->assertRedirect('/usuarios');

        $cuenta = Usuario::where('usuario', 'jorge.c')->first();

        $this->assertNotNull($cuenta);
        $this->assertTrue($cuenta->puedeIngresar());
        $this->assertTrue($cuenta->tienePermiso('ventas.registrar'));
        $this->assertFalse($cuenta->tienePermiso('usuarios.gestionar'));
    }

    public function test_un_empleado_no_puede_tener_dos_cuentas(): void
    {
        $ana = Empleado::where('documento', '10000001')->firstOrFail();

        $this->actingAs($this->admin())->post('/usuarios', [
            'empleado_id' => $ana->id,
            'rol_id' => Rol::first()->id,
            'usuario' => 'ana.segunda',
            'password' => 'clave-segura-1',
            'password_confirmation' => 'clave-segura-1',
        ])->assertSessionHasErrors('empleado_id');
    }

    public function test_nadie_se_quita_el_acceso_a_si_mismo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/usuarios/{$admin->id}/acceso")
            ->assertRedirect();

        $this->assertTrue((bool) $admin->fresh()->activo);
    }

    public function test_desactivar_una_cuenta_no_toca_el_vinculo_laboral(): void
    {
        $cajero = $this->cajero();

        $this->actingAs($this->admin())
            ->patch("/usuarios/{$cajero->id}/acceso")
            ->assertRedirect();

        $this->assertFalse((bool) $cajero->fresh()->activo);
        $this->assertSame('ACTIVO', $cajero->empleado->fresh()->estado);
    }

    public function test_se_puede_cambiar_la_propia_contrasena(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['password_hash' => bcrypt('admin123')])->save();

        $this->actingAs($admin)->put('/perfil/password', [
            'password_actual' => 'admin123',
            'password' => 'otra-clave-larga',
            'password_confirmation' => 'otra-clave-larga',
        ])->assertRedirect();

        $this->assertTrue(
            Hash::check('otra-clave-larga', $admin->fresh()->password_hash)
        );
    }
}
