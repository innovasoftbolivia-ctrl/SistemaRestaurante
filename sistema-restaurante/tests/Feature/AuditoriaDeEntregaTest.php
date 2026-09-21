<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\Pedidos;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lo que encontró la auditoría previa a la entrega (21/09/2026), cada caso
 * con lo que pasaba.
 */
class AuditoriaDeEntregaTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function adminConClave(string $clave = 'admin123'): Usuario
    {
        $cuenta = $this->u('admin');
        $cuenta->forceFill(['password_hash' => Hash::make($clave), 'activo' => 1, 'intentos_fallidos' => 0])->save();

        return $cuenta;
    }

    /** Una cuenta con `usuarios.gestionar` y `empleados.gestionar`, y nada más. */
    private function supervisor(): Usuario
    {
        $rol = Rol::create(['nombre' => 'Supervisor', 'descripcion' => 'Da de alta cajeros', 'activo' => true]);
        $rol->permisos()->sync(Permiso::whereIn('codigo', ['usuarios.gestionar', 'empleados.gestionar'])->pluck('id'));

        return Usuario::create([
            'empleado_id' => 4,
            'rol_id' => $rol->id,
            'usuario' => 'supervisor',
            'password_hash' => Hash::make('clave-del-supervisor'),
            'password_actualizado_en' => now(),
            'debe_cambiar_password' => false,
            'activo' => true,
        ]);
    }

    // ----------------------------------------------------------------- login

    /** `ádmin` entraba a `admin` (la columna no distingue acentos) con su propio contador de intentos. */
    public function test_una_variante_acentuada_del_usuario_no_entra(): void
    {
        $this->adminConClave();

        $this->post(route('login.store'), ['usuario' => 'ádmin', 'password' => 'admin123'])
            ->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    public function test_el_usuario_en_mayusculas_sigue_entrando(): void
    {
        $this->adminConClave();

        $this->post(route('login.store'), ['usuario' => ' ADMIN ', 'password' => 'admin123']);

        $this->assertAuthenticated();
    }

    /** El intento 256 reventaba el TINYINT con un 500 y no quedaba en la bitácora. */
    public function test_el_contador_de_intentos_no_desborda(): void
    {
        $cuenta = $this->adminConClave();
        $cuenta->forceFill(['intentos_fallidos' => 255])->save();

        $this->post(route('login.store'), ['usuario' => 'admin', 'password' => 'otra-cosa'])
            ->assertRedirect()
            ->assertSessionHasErrors('usuario');

        $this->assertSame(255, (int) $cuenta->fresh()->intentos_fallidos);
    }

    // ------------------------------------------------- permisos de más

    public function test_quien_administra_usuarios_no_se_agrega_permisos(): void
    {
        $supervisor = $this->supervisor();
        $todos = Permiso::pluck('id')->all();

        $this->actingAs($supervisor)
            ->put(route('roles.update', $supervisor->rol_id), ['nombre' => 'Supervisor', 'activo' => 1, 'permisos' => $todos])
            ->assertSessionHas('error');

        $this->assertSame(2, $supervisor->rol->permisos()->count());

        $this->actingAs($supervisor)
            ->post(route('roles.store'), ['nombre' => 'Todo', 'permisos' => $todos])
            ->assertSessionHas('error');

        $this->assertFalse(Rol::where('nombre', 'Todo')->exists());
    }

    public function test_quien_administra_usuarios_no_toca_al_administrador(): void
    {
        $supervisor = $this->supervisor();
        $admin = $this->u('admin');
        $hash = $admin->password_hash;

        // Restablecerle la clave al administrador era quedarse con su cuenta.
        $this->actingAs($supervisor)
            ->put(route('usuarios.update', $admin), [
                'rol_id' => $admin->rol_id,
                'usuario' => 'admin',
                'password' => 'clave-nueva-123',
                'password_confirmation' => 'clave-nueva-123',
                'activo' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertSame($hash, $admin->fresh()->password_hash);

        // Ni subir a otro al rol de Administrador.
        $cajero = $this->u('cajero1');

        $this->actingAs($supervisor)
            ->put(route('usuarios.update', $cajero), [
                'rol_id' => $admin->rol_id,
                'usuario' => 'cajero1',
                'activo' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertNotSame($admin->rol_id, $cajero->fresh()->rol_id);
    }

    public function test_el_administrador_sigue_pudiendo_con_todo(): void
    {
        $cajero = $this->u('cajero1');

        $this->actingAs($this->u('admin'))
            ->put(route('usuarios.update', $cajero), [
                'rol_id' => $cajero->rol_id,
                'usuario' => 'cajero1',
                'password' => 'clave-nueva-123',
                'password_confirmation' => 'clave-nueva-123',
                'activo' => 1,
            ])
            ->assertSessionHas('exito');
    }

    /** Cambiar la clave propia desde Usuarios echaba al administrador en la página siguiente. */
    public function test_quien_cambia_su_clave_desde_usuarios_sigue_dentro(): void
    {
        $admin = $this->adminConClave('clave-vieja-2026');

        $this->actingAs($admin)->get(route('usuarios.edit', $admin))->assertOk();
        $this->put(route('usuarios.update', $admin), [
            'rol_id' => $admin->rol_id,
            'usuario' => 'admin',
            'password' => 'clave-nueva-2026',
            'password_confirmation' => 'clave-nueva-2026',
            'password_actual' => 'clave-vieja-2026',
            'activo' => 1,
        ])->assertSessionHasNoErrors()->assertSessionHas('exito');

        // Lo que `auth.session` compara en la petición siguiente: tiene que ser
        // la huella de la clave NUEVA, o esa petición cierra la sesión.
        $this->assertTrue(Hash::check('clave-nueva-2026', $admin->fresh()->password_hash));
        $this->assertSame(auth()->guard('web')->hashPasswordForCookie($admin->fresh()->password_hash), session('password_hash_web'));
    }

    // ---------------------------------------------------------- impuestos

    /** PHP 8.4 dejó de pre-redondear `round()`: 1.5 × 0.15 daba 0.22 y MySQL, 0.23. */
    public function test_el_impuesto_encima_redondea_como_la_base(): void
    {
        $this->assertSame(0.23, Config::impuestoDe(1.5, 0.15));
        $this->assertSame(0.33, Config::impuestoDe(2.5, 0.13));

        $enLaBase = (float) DB::selectOne('SELECT ROUND(2.50 * 0.13, 2) AS i')->i;
        $this->assertSame($enLaBase, Config::impuestoDe(2.5, 0.13));
    }

    public function test_el_precio_de_estante_es_el_que_se_cobra(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->where('clave', 'precios_incluyen_impuesto')->update(['valor' => '0']);
        Config::olvidar();

        $producto = Producto::where('afecto_impuesto', 1)->firstOrFail();
        $producto->forceFill(['precio_venta' => 2.50])->save();

        $this->assertSame(2.83, $producto->fresh()->precio_estante);

        Config::olvidar();
    }

    /** Un pedido por volver a cobrar guarda sus precios en el modo en que se pidió. */
    public function test_no_se_cambia_el_modo_de_precios_con_pedidos_por_cobrar(): void
    {
        $cajero = $this->u('cajero1');
        Pedidos::abrir(Pedido::LOCAL, $cajero);

        $modo = DB::table('configuracion')->where('clave', 'precios_incluyen_impuesto')->value('valor');

        $this->actingAs($this->u('admin'))
            ->from(route('configuracion.edit'))
            ->put(route('configuracion.update'), $this->configuracionActual([
                'precios_incluyen_impuesto' => $modo === '1' ? '0' : '1',
            ]))
            ->assertSessionHas('error');

        $this->assertSame($modo, DB::table('configuracion')->where('clave', 'precios_incluyen_impuesto')->value('valor'));
    }

    // ------------------------------------------------------------ reportes

    /** Un año mal tecleado armaba millones de días en memoria. */
    public function test_un_rango_absurdo_no_cuelga_el_reporte(): void
    {
        $respuesta = $this->actingAs($this->u('admin'))
            ->get(route('reportes.ventas', ['desde' => '0001-01-01', 'hasta' => now()->toDateString()]))
            ->assertOk();

        $this->assertLessThanOrEqual(3653, count($respuesta->viewData('porDia')));
    }

    /** A la 01:30, «Hoy» abría la jornada que empieza a las 05:00, vacía, y escondía lo vendido esa noche. */
    public function test_el_atajo_hoy_es_la_jornada_en_curso(): void
    {
        $this->travelTo(now()->setTime(1, 30));
        $jornada = Config::jornadaActual();

        $this->assertNotSame(now()->toDateString(), $jornada);

        $this->actingAs($this->u('admin'))
            ->get(route('reportes.ventas'))
            ->assertOk()
            ->assertSee('?desde='.$jornada.'&hasta='.$jornada, false);
    }

    public function test_una_fecha_ilegible_no_da_error(): void
    {
        $this->actingAs($this->u('admin'))
            ->get(route('reportes.ventas', ['desde' => 'ayer', 'hasta' => '2026-13-45']))
            ->assertOk();
    }

    /** @param  array<string, string>  $cambios */
    private function configuracionActual(array $cambios): array
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');
        $tasa = (float) $valor('tasa_impuesto');

        return array_merge([
            'negocio_nombre' => $valor('negocio_nombre'),
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'cobra_impuesto' => '1',
            'tasa_impuesto' => $tasa > 0 ? rtrim(rtrim(number_format($tasa * 100, 2, '.', ''), '0'), '.') : '13',
            'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto') === '1' ? '1' : '0',
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
            'hora_corte_jornada' => $valor('hora_corte_jornada'),
            'serie_factura' => (string) DB::table('tipos_comprobante')->where('codigo', 'FAC')->value('serie_por_omision_id'),
            'serie_recibo' => (string) DB::table('tipos_comprobante')->where('codigo', 'REC')->value('serie_por_omision_id'),
        ], $cambios);
    }
}
