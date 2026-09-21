<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\ReglasEnPhp;
use App\Services\Respaldos;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Los arreglos de la auditoría final (17/09/2026), cada uno con lo que pasaba.
 */
class AuditoriaFinalTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(Usuario $usuario): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 200))->fresh();
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    private function comun(): Producto
    {
        return Producto::activos()->orderBy('id')->firstOrFail();
    }

    private function archivo(string $relativo): string
    {
        return (string) file_get_contents(base_path('../'.$relativo));
    }

    // ================================================================ instalación

    public function test_los_respaldos_de_esta_maquina_no_viajan_a_la_imagen_y_la_carpeta_nace_con_dueno(): void
    {
        $this->assertStringContainsString('storage/app/respaldos/', $this->archivo('sistema-restaurante/.dockerignore'));
        $this->assertMatchesRegularExpression('/mkdir -p[^&]*storage\/app\/respaldos/s', $this->archivo('sistema-restaurante/Dockerfile.prod'));
        $this->assertMatchesRegularExpression('/chown -R www-data:www-data[^\n]*storage\/app\/respaldos/', $this->archivo('sistema-restaurante/docker/php/entrypoint.prod.sh'));
    }

    public function test_el_servidor_monta_la_copia_externa_limita_los_logs_y_el_puerto_se_configura(): void
    {
        $compose = $this->archivo('docker-compose.prod.yml');

        $this->assertSame(2, substr_count($compose, ':/respaldos-copia'), 'la copia externa se monta en la aplicación y en el programador');
        $this->assertSame(4, substr_count($compose, 'max-size: "10m"'), 'los cuatro servicios con tope de logs');
        $this->assertStringContainsString('"${APP_PUERTO:-8100}:80"', $compose);
    }

    /**
     * El script aplica los parches donde corresponde: al contenedor de
     * producción si está, y a la base que se le pida.
     */
    public function test_el_script_de_parches_encuentra_el_contenedor_de_produccion(): void
    {
        $script = $this->archivo('scripts/aplicar-parches.sh');

        // Producción primero, después el stack de desarrollo; y de esos, el
        // que esté corriendo.
        $this->assertStringContainsString('detectar "${RESTAURANTE_MYSQL:-}" restaurante_mysql_prod restaurante_mysql)', $script);
        $this->assertStringContainsString('{{.State.Running}}', $script);

        // Con BASE=otra, el `USE ventas_db;` de los parches se cambia por esa
        // base: si no, el parche se aplicaba a ventas_db y se anotaba en la otra.
        $this->assertStringContainsString('sed "s/^USE ventas_db;/USE \`$BASE\`;/"', $script);
    }

    public function test_todos_los_parches_fijan_la_codificacion(): void
    {
        foreach (glob(base_path('../docs/sql/parches/*.sql')) as $ruta) {
            $this->assertStringContainsString('SET NAMES utf8mb4;', (string) file_get_contents($ruta), basename($ruta));
        }
    }

    /** Las rutinas restauradas vuelven con el modo estricto de la base, no con el relajado de la carga. */
    public function test_el_respaldo_recrea_las_rutinas_con_el_modo_de_la_base(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Sin procedimientos ni triggers no hay rutinas que recrear.');
        }

        $modo = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $sql = gzdecode(file_get_contents($ruta = Respaldos::crear()['base']));
        @unlink($ruta);

        $antesDeRutinas = substr($sql, 0, (int) strpos($sql, 'DROP PROCEDURE IF EXISTS'));
        $this->assertStringContainsString("SET SQL_MODE = '{$modo}';", $antesDeRutinas);
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $modo);
    }

    // ================================================================ QR

    public function test_un_qr_vencido_no_se_confirma_a_mano(): void
    {
        $cajero = $this->u('cajero1');
        $cobro = CobrosQr::generar($this->turno($cajero), $cajero, 10);
        $cobro->update(['estado' => CobroQr::EXPIRADO]);

        $this->assertThrows(fn () => CobrosQr::confirmarAMano($cobro->fresh(), $cajero), RuntimeException::class, 'venció');
        $this->assertSame(CobroQr::EXPIRADO, $cobro->fresh()->estado);
    }

    /** Lo confirmado a mano queda a la vista del administrador para cotejar con el banco. */
    public function test_los_qr_confirmados_a_mano_se_ven_en_la_caja(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, 12), $cajero);

        $this->actingAs($this->u('admin'))->get(route('caja.show', $turno))->assertOk()->assertSee('data-qr-a-mano', false);
        $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()->assertDontSee('data-qr-a-mano', false);

        $turno = $turno->fresh();
        Cajas::cerrar($turno, $this->u('admin'), $turno->efectivoEsperado(), null, 0, $turno->huella());
        $this->actingAs($this->u('admin'))->get(route('caja.imprimir', $turno))->assertOk()->assertSee('data-qr-a-mano', false);
    }

    /** El mostrador ofrece usar un QR ya pagado que no llegó a venta, y guarda la venta en curso ante un error. */
    public function test_el_mostrador_no_pierde_un_qr_pagado(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);

        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
            ->assertViewHas('qrSinVenta', fn ($lista) => $lista->isEmpty())
            ->assertSee("sessionStorage.setItem('pos-venta-en-curso'", false)
            ->assertSee('restaurarVentaEnCurso()', false);

        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, 8), $cajero);

        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
            ->assertViewHas('qrSinVenta', fn ($lista) => $lista->pluck('id')->all() === [$cobro->id])
            ->assertSee('data-qr-libres', false);

        // Una venta rechazada vuelve con la marca de error, para restaurar lo armado.
        $this->actingAs($cajero)->post(route('pos.store'), [
            'lineas' => [['producto_id' => $this->comun()->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->efectivo(), 'monto_recibido' => '9999']],
        ])->assertRedirect();
        $this->actingAs($cajero)->get(route('pos.index'))->assertViewHas('huboError', true);

        // Otro cajero, en su propio turno, no ve ese QR: no lo puede usar.
        $otro = $this->u('admin');
        Cajas::abrir(Caja::create(['nombre' => 'Caja de la barra', 'activo' => 1]), $otro, 50);

        $this->actingAs($otro)->get(route('pos.index'))->assertOk()
            ->assertViewHas('qrSinVenta', fn ($lista) => $lista->isEmpty());
    }

    // ================================================================ dinero

    /** Anular una venta con descuento deja el cajón como estaba antes de cobrarla. */
    public function test_anular_una_venta_saca_del_arqueo_lo_que_habia_metido(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();
        $admin = $this->u('admin');
        $p = $this->comun();
        $p->forceFill(['precio_venta' => '0.50'])->save();
        $turno = $this->turno($admin);
        $antes = $turno->fresh()->efectivoEsperado();

        $venta = Ventas::registrar(sesion: $turno, usuario: $admin,
            lineas: [['producto_id' => $p->id, 'cantidad' => 300]],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]], descuento: 2.00);

        $this->assertSame('148.00', $venta->fresh()->total);
        $this->assertSame(round($antes + 148.00, 2), round($turno->fresh()->efectivoEsperado(), 2));

        Ventas::anular($venta->fresh(), $admin, 'Se cobró de más');

        $this->assertSame('ANULADA', $venta->fresh()->estado);
        $this->assertSame(round($antes, 2), round($turno->fresh()->efectivoEsperado(), 2));
    }

    // ================================================================ catálogo

    /**
     * Quien mantiene la carta, sin el permiso de eliminar. En el restaurante
     * eso es el administrador, pero el rol se puede acotar y hay que
     * comprobar que el acotado no descatalogue por la puerta de atrás.
     */
    private function encargadoDeCarta(): Usuario
    {
        $rol = Rol::create(['nombre' => 'Encargado de la carta', 'activo' => 1]);
        $rol->permisos()->sync(Permiso::where('codigo', 'productos.gestionar')->pluck('id'));

        return Usuario::create([
            'empleado_id' => 4,
            'rol_id' => $rol->id,
            'usuario' => 'carta1',
            'password_hash' => Hash::make('carta-de-prueba'),
            'debe_cambiar_password' => 0,
            'activo' => 1,
        ]);
    }

    /** Desactivar es eliminar: quien edita la carta no descataloga. */
    public function test_quien_edita_la_carta_no_desactiva_editando(): void
    {
        $encargado = $this->encargadoDeCarta();
        $p = Producto::where('codigo', 'P-0004')->firstOrFail();
        $datos = $p->only(['categoria_id', 'codigo', 'precio_venta', 'afecto_impuesto']);

        $this->actingAs($encargado)->put(route('productos.update', $p), ['nombre' => $p->nombre.' editado', 'activo' => 0] + $datos)
            ->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->activo);
        $this->assertStringEndsWith('editado', $p->fresh()->nombre);

        $categoria = Categoria::where('activo', 1)->firstOrFail();
        $this->actingAs($encargado)->put(route('categorias.update', $categoria), ['nombre' => $categoria->nombre, 'activo' => 0]);
        $this->assertTrue((bool) $categoria->fresh()->activo);

        $this->actingAs($encargado)->get(route('productos.edit', $p))->assertOk()->assertDontSee('Disponible en el menú');

        // El administrador sí.
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->actingAs($this->u('admin'))->put(route('productos.update', $p), ['nombre' => $p->fresh()->nombre, 'activo' => 0] + $datos)
            ->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->activo);
    }

    /**
     * El formulario que mueve dinero lleva su número de envío único.
     *
     * Que el número funcione se prueba donde más importa, en el cobro de una
     * cuenta (`CobroDePedidoTest`): ahí detrás hay una venta y un comprobante.
     * Aquí solo se comprueba que la pantalla de caja lo sigue emitiendo.
     */
    public function test_el_formulario_de_caja_lleva_su_numero_de_envio(): void
    {
        $admin = $this->u('admin');
        $turno = $this->turno($admin);

        $this->actingAs($admin)->get(route('caja.show', $turno))->assertOk()
            ->assertSee('name="_envio"', false);
    }

    // ================================================================ ingreso

    /** Cambiando de dirección ya no se prueban contraseñas sin fin contra una cuenta. */
    public function test_la_cuenta_se_bloquea_aunque_los_intentos_vengan_de_direcciones_distintas(): void
    {
        DB::table('usuarios')->where('usuario', 'admin')->update(['password_hash' => Hash::make('clave-buena-123')]);

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.{$i}"])->post('/login', ['usuario' => 'admin', 'password' => 'mala-'.$i]);
        }

        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.99'])->post('/login', ['usuario' => 'admin', 'password' => 'clave-buena-123'])
            ->assertSessionHasErrors(['usuario' => 'Demasiados intentos fallidos con esta cuenta. Queda bloqueada 15 minuto(s); si no fuiste tú, avisa al administrador.']);
        $this->assertGuest();

        // Otra cuenta no queda bloqueada por eso.
        DB::table('usuarios')->where('usuario', 'cajero1')->update(['password_hash' => Hash::make('clave-cajero-123')]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.99'])->post('/login', ['usuario' => 'cajero1', 'password' => 'clave-cajero-123'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
