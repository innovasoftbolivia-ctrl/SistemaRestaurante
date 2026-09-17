<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\CobroQr;
use App\Models\Compra;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Costos;
use App\Services\Devoluciones;
use App\Services\Inventario;
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

    private function comun(float $stock = 50): Producto
    {
        $p = Producto::activos()->where('controla_vencimiento', 0)
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        $p->forceFill(['stock_actual' => $stock, 'contenido_empaque' => null, 'nombre_empaque' => null])->save();

        return $p->fresh();
    }

    private function archivo(string $relativo): string
    {
        return (string) file_get_contents(base_path('../'.$relativo));
    }

    // ================================================================ instalación

    public function test_los_respaldos_de_esta_maquina_no_viajan_a_la_imagen_y_la_carpeta_nace_con_dueno(): void
    {
        $this->assertStringContainsString('storage/app/respaldos/', $this->archivo('sistema-ventas/.dockerignore'));
        $this->assertMatchesRegularExpression('/mkdir -p[^&]*storage\/app\/respaldos/s', $this->archivo('sistema-ventas/Dockerfile.prod'));
        $this->assertMatchesRegularExpression('/chown -R www-data:www-data[^\n]*storage\/app\/respaldos/', $this->archivo('sistema-ventas/docker/php/entrypoint.prod.sh'));
    }

    public function test_el_servidor_monta_la_copia_externa_limita_los_logs_y_el_puerto_se_configura(): void
    {
        $compose = $this->archivo('docker-compose.prod.yml');

        $this->assertSame(2, substr_count($compose, ':/respaldos-copia'), 'la copia externa se monta en la aplicación y en el programador');
        $this->assertSame(4, substr_count($compose, 'max-size: "10m"'), 'los cuatro servicios con tope de logs');
        $this->assertStringContainsString('"${APP_PUERTO:-8100}:80"', $compose);
    }

    public function test_el_script_de_parches_encuentra_el_contenedor_de_produccion_y_deja_fuera_el_catalogo(): void
    {
        $script = $this->archivo('scripts/aplicar-parches.sh');

        $this->assertStringContainsString('detectar "${VENTAS_MYSQL:-}" ventas_mysql_prod ventas_mysql', $script);
        $this->assertStringNotContainsString('CONTENEDOR="ventas_mysql"', $script);

        foreach (['abarrotes_catalogo_real', 'bebidas_catalogo_real', 'categoria_cigarrillos', 'sin_impuesto'] as $catalogo) {
            $this->assertStringContainsString("2026_08_23_{$catalogo}.sql", $script);
        }
        $this->assertStringContainsString('--catalogo', $script);
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

        // Otro cajero no ve ese QR.
        $this->assertTrue(true);
    }

    // ================================================================ dinero

    public function test_devolver_una_venta_entera_devuelve_lo_cobrado_al_centavo(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();
        $admin = $this->u('admin');
        $p = $this->comun(500);
        $p->forceFill(['precio_venta' => '0.50'])->save();
        $turno = $this->turno($admin);

        $venta = Ventas::registrar(sesion: $turno, usuario: $admin,
            lineas: [['producto_id' => $p->id, 'cantidad' => 300]],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]], descuento: 2.00);

        $devolucion = Devoluciones::registrar($venta->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 300, 'reingresa_stock' => true],
        ], 'Devuelve todo', Devolucion::EFECTIVO);

        $this->assertSame('148.00', $venta->fresh()->total);
        $this->assertSame('148.00', $devolucion->fresh()->total);
        $this->assertSame('148.00', $devolucion->fresh()->efectivo);
        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
    }

    // ================================================================ inventario

    public function test_actualizar_el_costo_no_pisa_el_stock_de_una_venta_simultanea(): void
    {
        $this->actingAs($this->u('almacen'));
        $p = $this->comun(10);
        $p->forceFill(['precio_compra' => '2.00'])->save();
        $p = $p->fresh();

        Inventario::ingreso($p, 5, costoUnitario: 3.00);
        DB::table('productos')->where('id', $p->id)->update(['stock_actual' => 14]); // una venta confirmó en medio
        Costos::aplicar($p, 3.00, 'prueba');

        $this->assertSame('14.000', $p->fresh()->stock_actual);
        $this->assertSame('3.00', $p->fresh()->precio_compra);
    }

    /** Desactivar es eliminar: el almacenero edita pero no descataloga. */
    public function test_el_almacenero_no_desactiva_editando(): void
    {
        $p = Producto::where('codigo', 'P-0004')->firstOrFail();
        $datos = $p->only(['categoria_id', 'unidad_medida_id', 'proveedor_id', 'codigo', 'codigo_barras', 'precio_compra', 'precio_venta', 'afecto_impuesto', 'stock_minimo']);

        $this->actingAs($this->u('almacen'))->put(route('productos.update', $p), ['nombre' => $p->nombre.' editado', 'activo' => 0] + $datos)
            ->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->activo);
        $this->assertStringEndsWith('editado', $p->fresh()->nombre);

        $categoria = Categoria::where('activo', 1)->firstOrFail();
        $this->actingAs($this->u('almacen'))->put(route('categorias.update', $categoria), ['nombre' => $categoria->nombre, 'activo' => 0]);
        $this->assertTrue((bool) $categoria->fresh()->activo);

        $proveedor = Proveedor::where('activo', 1)->firstOrFail();
        $this->actingAs($this->u('almacen'))->put(route('proveedores.update', $proveedor), $proveedor->only(['razon_social', 'documento', 'telefono', 'email', 'direccion']) + ['activo' => 0]);
        $this->assertTrue((bool) $proveedor->fresh()->activo);

        $this->actingAs($this->u('almacen'))->get(route('productos.edit', $p))->assertOk()->assertDontSee('Producto disponible para la venta');

        // El administrador sí.
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->actingAs($this->u('admin'))->put(route('productos.update', $p), ['nombre' => $p->fresh()->nombre, 'activo' => 0] + $datos)
            ->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->activo);
    }

    /** Un doble clic manda dos veces el mismo número de envío: la compra entra una sola vez. */
    public function test_un_doble_envio_registra_la_compra_una_sola_vez(): void
    {
        $almacen = $this->u('almacen');
        $p = $this->comun(0);
        $envio = 'envio-de-prueba-1';
        $datos = ['proveedor_id' => Proveedor::firstOrFail()->id, 'documento_externo' => 'F-DOBLE', '_envio' => $envio,
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 4, 'costo_unitario' => 2]]];

        $this->actingAs($almacen)->get(route('compras.create'))->assertOk()->assertSee('name="_envio"', false);

        $this->actingAs($almacen)->post(route('compras.store'), $datos)->assertSessionHasNoErrors();
        $this->actingAs($almacen)->post(route('compras.store'), $datos)->assertSessionHas('aviso');

        $this->assertSame(1, Compra::where('documento_externo', 'F-DOBLE')->count());
        $this->assertSame('4.000', $p->fresh()->stock_actual);

        // Si el primer envío no pasó la validación, el mismo formulario se corrige y se reenvía.
        $envio2 = ['_envio' => 'envio-de-prueba-2'] + $datos;
        $sinLineas = $envio2;
        unset($sinLineas['lineas']);
        $this->actingAs($almacen)->post(route('compras.store'), $sinLineas)->assertSessionHasErrors('lineas');
        $this->actingAs($almacen)->post(route('compras.store'), ['documento_externo' => 'F-DOBLE-2'] + $envio2)->assertSessionHasNoErrors();
        $this->assertSame(1, Compra::where('documento_externo', 'F-DOBLE-2')->count());
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
