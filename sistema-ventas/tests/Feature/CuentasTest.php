<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cuentas y accesos: lo que tiene que pasar cuando un administrador quita un
 * acceso, cambia una contraseña o reorganiza los roles.
 */
class CuentasTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    // ================================================================ rol desactivado

    /** «Desactivar» un rol con cuentas tiene que quitarles el acceso. */
    public function test_un_rol_desactivado_deja_a_sus_cuentas_sin_permisos_ni_acceso(): void
    {
        Rol::where('nombre', 'Almacenero')->update(['activo' => 0]);
        $almacen = $this->usuario('almacen');

        $this->assertFalse($almacen->tienePermiso('inventario.ajustar'));
        $this->assertFalse($almacen->puedeIngresar());

        $this->actingAs($almacen)->get(route('inventario.index'))->assertRedirect(route('login'));
    }

    // ================================================================ sesiones

    /** Una contraseña cambiada echa a quien tenía la sesión abierta con la anterior. */
    public function test_cambiar_la_contrasena_cierra_las_otras_sesiones(): void
    {
        $cajero = $this->usuario('cajero1');
        $this->actingAs($cajero)->get(route('perfil.edit'))->assertOk();

        // Otro dispositivo, o un administrador, cambió la contraseña.
        $cajero->forceFill(['password_hash' => Hash::make('otra-clave-distinta')])->save();

        $this->get(route('perfil.edit'))->assertRedirect(route('login'));
    }

    public function test_quien_cambia_su_contrasena_sigue_dentro(): void
    {
        $admin = $this->usuario('admin');
        $admin->forceFill(['password_hash' => Hash::make('clave-vieja-2026')])->save();

        $this->actingAs($admin)->get(route('perfil.edit'))->assertOk();
        $this->put(route('perfil.password'), [
            'password_actual' => 'clave-vieja-2026',
            'password' => 'clave-nueva-2026',
            'password_confirmation' => 'clave-nueva-2026',
        ])->assertSessionHasNoErrors();

        $this->get(route('perfil.edit'))->assertOk();
    }

    // ================================================================ último administrador

    public function test_no_se_le_quita_la_administracion_al_unico_rol_que_la_tiene(): void
    {
        $admin = $this->usuario('admin');
        $rol = Rol::where('nombre', 'Administrador')->firstOrFail();
        $sinUsuarios = Permiso::where('codigo', '<>', 'usuarios.gestionar')->pluck('id')->all();

        $this->actingAs($admin)->put(route('roles.update', $rol), [
            'nombre' => $rol->nombre, 'activo' => 1, 'permisos' => $sinUsuarios,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'sin ninguna cuenta'));

        $this->assertTrue($admin->fresh()->tienePermiso('usuarios.gestionar'));

        $this->actingAs($admin)->delete(route('roles.destroy', $rol))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'sin ninguna cuenta'));
        $this->assertTrue((bool) $rol->fresh()->activo);
    }

    public function test_no_se_da_de_baja_al_unico_administrador(): void
    {
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->delete(route('empleados.destroy', $admin->empleado_id), [
            'fecha_cese' => now()->toDateString(), 'motivo_cese' => 'Renuncia',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'sin ninguna cuenta'));

        $this->assertSame('ACTIVO', $admin->fresh()->empleado->estado);
    }

    /** Con otra cuenta administradora, el cambio sí se permite. */
    public function test_con_otra_cuenta_administradora_si_se_puede(): void
    {
        $admin = $this->usuario('admin');
        $gestionar = Permiso::where('codigo', 'usuarios.gestionar')->value('id');
        $almacenero = Rol::where('nombre', 'Almacenero')->firstOrFail();
        $almacenero->permisos()->attach($gestionar);

        $rol = Rol::where('nombre', 'Administrador')->firstOrFail();
        $this->actingAs($admin)->put(route('roles.update', $rol), [
            'nombre' => $rol->nombre, 'activo' => 1,
            'permisos' => Permiso::where('codigo', '<>', 'usuarios.gestionar')->pluck('id')->all(),
        ])->assertSessionMissing('error');

        $this->assertFalse($admin->fresh()->tienePermiso('usuarios.gestionar'));
    }
}
