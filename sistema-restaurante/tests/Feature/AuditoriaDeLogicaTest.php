<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\CompraSugerida;
use App\Services\DevolucionesCompra;
use App\Services\Inventario;
use App\Services\Pedidos;
use App\Services\ReglasEnPhp;
use App\Services\TomasInventario;
use App\Services\Ventas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * Lo que encontró la auditoría del sistema, arreglado y con su prueba: cada
 * caso describe cómo se rompía antes.
 */
class AuditoriaDeLogicaTest extends TestCase
{
    use ConDatosDeInventario;
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(?Usuario $quien = null): SesionCaja
    {
        $quien ??= $this->usuario('admin');

        return Cajas::sesionDe($quien) ?? Cajas::abrir(Caja::firstOrFail(), $quien, 100);
    }

    private function efectivo(): array
    {
        return [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]];
    }

    /** La bebida con stock: se compra hecha y se vende por unidad. */
    private function bebida(float $stock = 20, float $minimo = 6): Producto
    {
        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'stock_minimo' => $minimo, 'costo' => 4, 'stock_actual' => 0,
        ])->save();

        if ($stock > 0) {
            Inventario::inicial($p->fresh(), $stock, $this->usuario('admin'));
        }

        return $p->fresh();
    }

    // ------------------------------------------------------ ventas y pedidos

    /**
     * Anular el cobro devuelve la bebida al stock, pero si después se cancela
     * el pedido, esa botella ya se la llevó el cliente: sale como consumida
     * sin cobrar. Antes quedaba en el sistema una botella que no existía.
     */
    public function test_cancelar_un_pedido_reabierto_descuenta_lo_que_ya_se_entrego(): void
    {
        $bebida = $this->bebida(20);
        $admin = $this->usuario('admin');

        $venta = Pedidos::venderEnMostrador($this->turno()->fresh(), $admin,
            [['producto_id' => $bebida->id, 'cantidad' => 2]], $this->efectivo());

        $this->assertSame(18.0, (float) $bebida->fresh()->stock_actual);

        Ventas::anular($venta->fresh(), $admin, 'Se cobró con otro medio');
        $this->assertSame(20.0, (float) $bebida->fresh()->stock_actual, 'al anular vuelve al stock');

        Pedidos::cancelar($venta->pedido->fresh(), $admin, 'El cliente se fue');

        $this->assertSame(18.0, (float) $bebida->fresh()->stock_actual);
        $movimiento = $bebida->fresh()->movimientos()->latest('id')->first();
        $this->assertSame(['SALIDA', 'AJUSTE'], [$movimiento->tipo, $movimiento->origen]);
        $this->assertStringContainsString('Entregado y no cobrado', $movimiento->motivo);
    }

    /** Un descuento no puede dejar la venta en cero: eso no es una venta. */
    public function test_una_venta_en_cero_no_se_registra(): void
    {
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede cubrirla entera');

        Ventas::registrar(
            sesion: $this->turno()->fresh(), usuario: $this->usuario('admin'),
            lineas: [['producto_id' => $plato->id, 'cantidad' => 1]],
            pagos: $this->efectivo(),
            descuento: (float) $plato->precio_venta,
        );
    }

    /** Sin número de envío no se procesa: una petición armada a mano repetía la venta. */
    public function test_un_envio_sin_numero_no_se_procesa(): void
    {
        $this->sinNumeroDeEnvio = true;
        $turno = $this->turno($this->usuario('cajero1'));
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->actingAs($this->usuario('cajero1'))->post(route('pos.store'), [
            'lineas' => [['producto_id' => $plato->id, 'cantidad' => 1]],
            'pagos' => $this->efectivo(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'Recarga la página'));

        $this->assertSame(0, $turno->fresh()->ventas()->count());
    }

    // ---------------------------------------------------------------- caja

    /** «0.5» y «0.50» son la misma moneda: dos veces en el mismo envío, se rechaza. */
    public function test_el_arqueo_no_admite_la_misma_denominacion_dos_veces(): void
    {
        $sesion = Cajas::abrir(Caja::create(['nombre' => 'Caja auditoría', 'activo' => 1]), $this->usuario('admin'), 3);

        $this->actingAs($this->usuario('admin'))->post(route('caja.cerrar', $sesion), [
            'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 3,
            'arqueo' => ['0.5' => 2, '0.50' => 4],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'dos veces'));

        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    // ----------------------------------------------------------- inventario

    /**
     * La planilla se llena y se guarda al rato: el stock del sistema es el del
     * momento en que se contó, no el de guardar. Antes, una venta en el medio
     * tapaba el faltante.
     */
    public function test_la_toma_usa_el_stock_del_momento_del_conteo(): void
    {
        $admin = $this->usuario('admin');

        // 09:00 se abre la toma con 20 en el sistema.
        Carbon::setTestNow(now()->subMinutes(20));
        $bebida = $this->bebida(20);
        $toma = TomasInventario::abrir($admin);

        // 09:05 se cuentan 18 en la bodega: faltan 2.
        Carbon::setTestNow(now()->addMinutes(5));
        $contadoEn = now();

        // 09:10 se venden 3 mientras la planilla sigue en la mano.
        Carbon::setTestNow(now()->addMinutes(5));
        Ventas::registrar(sesion: $this->turno()->fresh(), usuario: $admin,
            lineas: [['producto_id' => $bebida->id, 'cantidad' => 3]], pagos: $this->efectivo());
        $this->assertSame(17.0, (float) $bebida->fresh()->stock_actual);

        // 09:20 se guarda la planilla.
        Carbon::setTestNow(now()->addMinutes(10));
        $linea = TomasInventario::contar($toma->fresh(), $bebida->id, 18, $admin, $contadoEn);

        $this->assertSame(20.0, (float) $linea->stock_sistema, 'el stock de cuando se contó');
        $this->assertSame(-2.0, (float) $linea->diferencia);

        TomasInventario::cerrar($toma->fresh(), $admin);
        $this->assertSame(15.0, (float) $bebida->fresh()->stock_actual, '17 menos las 2 que faltaban');

        Carbon::setTestNow();
    }

    /** No se devuelve al proveedor más de lo que hay en la bodega. */
    public function test_no_se_devuelve_mas_de_lo_que_hay_en_stock(): void
    {
        $bebida = $this->bebida(0);
        $admin = $this->usuario('admin');
        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora de prueba', 'documento' => '9080706']);
        $compra = Compras::registrar($proveedor, $admin, [['producto_id' => $bebida->id, 'cantidad' => 24, 'costo_unitario' => 4]]);

        Ventas::registrar(sesion: $this->turno()->fresh(), usuario: $admin,
            lineas: [['producto_id' => $bebida->id, 'cantidad' => 20]], pagos: $this->efectivo());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no se puede devolver más de lo que hay');

        DevolucionesCompra::registrar($compra, $admin, [
            ['compra_detalle_id' => $compra->detalle()->value('id'), 'cantidad' => 10],
        ], 'DEFECTO', 'NOTA_CREDITO');
    }

    /** Lo cambiado en el acto se puede volver a devolver: el reemplazo también puede venir malo. */
    public function test_lo_cambiado_en_el_acto_se_puede_devolver_de_nuevo(): void
    {
        $bebida = $this->bebida(0);
        $admin = $this->usuario('admin');
        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora del cambio', 'documento' => '1122334']);
        $compra = Compras::registrar($proveedor, $admin, [['producto_id' => $bebida->id, 'cantidad' => 12, 'costo_unitario' => 4]]);
        $linea = $compra->detalle()->first();

        DevolucionesCompra::registrar($compra, $admin, [['compra_detalle_id' => $linea->id, 'cantidad' => 12]], 'DEFECTO', 'REPUESTO');

        $this->assertSame(0.0, (float) $linea->fresh()->cantidad_devuelta, 'lo cambiado en el acto no cuenta como devuelto');
        $this->assertSame(12.0, (float) $bebida->fresh()->stock_actual, 'el cambio en el acto no mueve el stock');

        // El reemplazo vino mal: se devuelve, ahora a cuenta.
        $segunda = DevolucionesCompra::registrar($compra, $admin, [['compra_detalle_id' => $linea->id, 'cantidad' => 12]], 'DEFECTO', 'NOTA_CREDITO');

        $this->assertSame(12.0, (float) $segunda->detalle->sum('cantidad'));
        $this->assertSame(0.0, (float) $bebida->fresh()->stock_actual);
    }

    /** Una caja regalada (costo 0) no baja el costo del producto a cero. */
    public function test_una_compra_a_costo_cero_no_pisa_el_costo(): void
    {
        $bebida = $this->bebida(0);
        $admin = $this->usuario('admin');
        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora promo', 'documento' => '5544332']);

        Compras::registrar($proveedor, $admin, [['producto_id' => $bebida->id, 'cantidad' => 12, 'costo_unitario' => 0]]);

        $this->assertSame(4.0, (float) $bebida->fresh()->costo);
    }

    /** El costo por unidad de una caja guarda cuatro decimales: 25,00 / 12. */
    public function test_el_costo_por_unidad_de_una_caja_no_se_redondea_a_centavos(): void
    {
        $bebida = $this->bebida(0);
        $admin = $this->usuario('admin');
        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora decimales', 'documento' => '6677889']);

        $this->actingAs($admin)->post(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'lineas' => [['producto_id' => $bebida->id, 'empaques' => 10, 'sueltas' => '', 'costo' => '25.00', 'costo_por' => 'empaque']],
        ])->assertSessionHasNoErrors();

        $compra = $proveedor->compras()->latest('id')->first();
        $this->assertSame('2.0833', (string) $compra->detalle()->value('costo_unitario'));
        // 120 unidades: 10 cajas de 25,00 son 250,00, no 249,60.
        $this->assertSame(250.0, round((float) $compra->detalle()->value('cantidad') * 2.0833, 2));
    }

    /** Un ajuste manual no le inventa stock a un plato. */
    public function test_no_se_ajusta_el_stock_de_algo_que_no_lleva_inventario(): void
    {
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no lleva inventario');

        Inventario::ajuste($plato, 5, 'Prueba', $this->usuario('admin'));
    }

    /** La compra sugerida no vuelve a pedir lo que el proveedor ya debe reponer. */
    public function test_la_compra_sugerida_descuenta_lo_que_el_proveedor_debe(): void
    {
        $datos = $this->datosDeInventario($this->usuario('admin'));
        $bebida = Producto::findOrFail($datos['bebida']);
        $bebida->forceFill(['stock_minimo' => 24])->save();

        $linea = collect(CompraSugerida::porProveedor()->flatMap(fn ($g) => $g['lineas']))
            ->firstWhere('producto.id', $bebida->id);

        // Stock 22, mínimo 24, sin ventas: el objetivo es 48; el proveedor debe
        // 2 (la devolución pendiente), así que faltan 24, no 26.
        $this->assertSame(2.0, (float) $linea['por_reponer']);
        $this->assertSame(24.0, (float) $linea['cantidad']);
    }

    /** Volver a activar el inventario obliga a contar: mientras estuvo apagado no se descontó. */
    public function test_reactivar_el_inventario_pide_contar_lo_que_hay(): void
    {
        $bebida = $this->bebida(30);
        $admin = $this->usuario('admin');
        $bebida->forceFill(['controla_stock' => false])->save();

        $datos = [
            'codigo' => $bebida->codigo, 'nombre' => $bebida->nombre, 'categoria_id' => $bebida->categoria_id,
            'precio_venta' => $bebida->precio_venta, 'afecto_impuesto' => 1, 'activo' => 1,
            'controla_stock' => 1, 'stock_minimo' => 6, 'nombre_empaque' => 'Caja', 'contenido_empaque' => 12,
        ];

        $this->actingAs($admin)->put(route('productos.update', $bebida), $datos)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'escribe cuántas unidades hay hoy'));
        $this->assertFalse((bool) $bebida->fresh()->controla_stock);

        $this->actingAs($admin)->put(route('productos.update', $bebida), $datos + ['stock_inicial' => 5])
            ->assertSessionHasNoErrors();

        $this->assertSame(5.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame('AJUSTE', $bebida->fresh()->movimientos()->latest('id')->value('origen'));
    }

    /** El kardex no se edita ni se borra (lo impide la base; con la lógica en PHP no hay triggers). */
    public function test_el_kardex_no_se_edita_ni_se_borra(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Sin triggers: en modo PHP solo escribe App\Services\Inventario.');
        }

        $bebida = $this->bebida(10);
        $id = $bebida->movimientos()->value('id');

        $this->assertThrows(fn () => DB::table('movimientos_inventario')->where('id', $id)->update(['cantidad' => 99]), QueryException::class);
        $this->assertThrows(fn () => DB::table('movimientos_inventario')->where('id', $id)->delete(), QueryException::class);
    }

    // ------------------------------------------------------------ seguridad

    /** Un cajero no puede cesar a un empleado editándolo: eso es dar de baja. */
    public function test_cesar_editando_pide_el_permiso_de_dar_de_baja(): void
    {
        $empleado = $this->usuario('cajero1')->empleado;
        $sinBaja = Usuario::where('usuario', 'admin')->firstOrFail();
        $rol = $sinBaja->rol;
        $rol->permisos()->detach($rol->permisos->firstWhere('codigo', 'registros.eliminar')?->id);

        $this->actingAs($sinBaja->fresh())->put(route('empleados.update', $empleado), [
            'cargo_id' => $empleado->cargo_id, 'tipo_documento' => $empleado->tipo_documento,
            'documento' => $empleado->documento, 'nombres' => $empleado->nombres, 'apellidos' => $empleado->apellidos,
            'fecha_ingreso' => $empleado->fecha_ingreso->toDateString(), 'tipo_contrato' => $empleado->tipo_contrato,
            'estado' => 'SUSPENDIDO',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'dar de baja'));

        $this->assertSame('ACTIVO', $empleado->fresh()->estado);
    }

    /** La cuenta desactivada responde igual que una contraseña mala. */
    public function test_una_cuenta_sin_acceso_no_confirma_su_contrasena(): void
    {
        $cuenta = $this->usuario('cocina1');
        $cuenta->forceFill(['activo' => false, 'password_hash' => bcrypt('la-clave-correcta-1')])->save();

        $this->post(route('login.store'), ['usuario' => $cuenta->usuario, 'password' => 'la-clave-correcta-1'])
            ->assertSessionHasErrors(['usuario' => 'Usuario o contraseña incorrectos, o la cuenta no tiene acceso.']);
    }

    /** El bloqueo por cuenta no deja afuera al dispositivo desde el que ya entró. */
    public function test_el_bloqueo_de_una_cuenta_no_encierra_a_su_propio_dispositivo(): void
    {
        $cuenta = $this->usuario('cajero1');
        $cuenta->forceFill(['password_hash' => bcrypt('la-clave-del-cajero-1')])->save();

        $entrada = $this->post(route('login.store'), ['usuario' => $cuenta->usuario, 'password' => 'la-clave-del-cajero-1']);
        $entrada->assertRedirect();
        $galleta = $entrada->getCookie('dispositivos', false)?->getValue();
        $this->assertNotNull($galleta, 'el ingreso deja marcado el dispositivo');
        $this->post(route('logout'));

        // Alguien de afuera gasta el tope de la cuenta con contraseñas malas.
        // (Se limpia el tope por dirección entre intentos: en la realidad los
        // manda desde direcciones distintas, que es lo que este tope no frena.)
        for ($i = 0; $i < 12; $i++) {
            $this->post(route('login.store'), ['usuario' => $cuenta->usuario, 'password' => 'no-es-la-clave-'.$i]);
            RateLimiter::clear('login:'.$cuenta->usuario.'|127.0.0.1');
        }

        $this->post(route('login.store'), ['usuario' => $cuenta->usuario, 'password' => 'la-clave-del-cajero-1'])
            ->assertSessionHasErrors('usuario');

        // Desde su propia caja, con la marca del dispositivo, sí entra.
        $this->withUnencryptedCookie('dispositivos', $galleta)
            ->post(route('login.store'), ['usuario' => $cuenta->usuario, 'password' => 'la-clave-del-cajero-1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /** Una contraseña nueva no puede llevar dentro el nombre de usuario. */
    public function test_la_contrasena_no_puede_contener_el_usuario(): void
    {
        $this->actingAs($this->usuario('admin'))->put(route('perfil.password'), [
            'password_actual' => 'admin123',
            'password' => 'admin-2026-ok',
            'password_confirmation' => 'admin-2026-ok',
        ])->assertSessionHasErrors('password');
    }
}
