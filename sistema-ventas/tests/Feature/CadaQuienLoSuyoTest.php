<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CobroQr;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Devoluciones;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Quién ve qué y quién puede cambiar qué (pedido del 16/09/2026):
 *
 *   - cada cajero ve SOLO sus ventas, sus turnos, sus comprobantes, sus cobros
 *     y sus devoluciones; nadie más ve sus movimientos, salvo el administrador;
 *   - editar clientes y eliminar cualquier registro es del administrador;
 *   - el almacenero da de alta y edita el catálogo, pero no elimina.
 */
class CadaQuienLoSuyoTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $admin;

    private Usuario $cajero1;

    private Usuario $cajero2;

    private Usuario $almacen;

    private SesionCaja $turno1;

    private SesionCaja $turno2;

    private Venta $venta1;

    private Venta $venta2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $this->cajero1 = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $this->almacen = Usuario::where('usuario', 'almacen')->firstOrFail();
        $this->cajero2 = Usuario::create([
            'empleado_id' => 4,
            'rol_id' => $this->cajero1->rol_id,
            'usuario' => 'cajero2',
            'password_hash' => Hash::make('cajero2-clave'),
            'debe_cambiar_password' => 0,
            'activo' => 1,
        ]);

        $segunda = Caja::create(['nombre' => 'Caja 2', 'ubicacion' => 'Pasillo', 'activo' => 1]);
        $this->turno1 = Cajas::abrir(Caja::orderBy('id')->firstOrFail(), $this->cajero1, 100);
        $this->turno2 = Cajas::abrir($segunda, $this->cajero2, 100);

        $this->venta1 = $this->vender($this->turno1, $this->cajero1);
        $this->venta2 = $this->vender($this->turno2, $this->cajero2);
    }

    /**
     * Cambia de usuario con la sesión limpia, como quien cierra sesión y entra
     * otro: la sesión recuerda la contraseña de quien entró y, con otra cuenta,
     * manda al login.
     */
    private function como(Usuario $usuario): static
    {
        $this->flushSession();
        app('auth')->forgetGuards();

        return $this->actingAs($usuario);
    }

    private function vender(SesionCaja $turno, Usuario $cajero): Venta
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $producto->forceFill(['stock_actual' => 100])->save();

        return Ventas::registrar(
            sesion: $turno->fresh(),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 2]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    private function rolCon(string $nombre, array $codigos): Usuario
    {
        $rol = Rol::create(['nombre' => $nombre, 'activo' => 1]);
        $rol->permisos()->sync(Permiso::whereIn('codigo', $codigos)->pluck('id'));

        // Una cuenta por empleado: el rol de prueba necesita su propio empleado.
        $empleado = DB::table('empleados')->insertGetId([
            'cargo_id' => 1, 'tipo_documento' => 'CI', 'documento' => (string) random_int(20000000, 29999999),
            'nombres' => 'Encargado', 'apellidos' => 'De Prueba', 'fecha_ingreso' => now()->toDateString(),
            'tipo_contrato' => 'INDEFINIDO', 'estado' => 'ACTIVO',
        ]);

        return Usuario::create([
            'empleado_id' => $empleado,
            'rol_id' => $rol->id,
            'usuario' => strtolower(str_replace(' ', '', $nombre)),
            'password_hash' => Hash::make('clave-de-prueba'),
            'debe_cambiar_password' => 0,
            'activo' => 1,
        ]);
    }

    // ================================================================ cada cajero, lo suyo

    public function test_cada_cajero_ve_solo_sus_ventas_y_comprobantes(): void
    {
        $listado = $this->como($this->cajero2)->get(route('ventas.index'))->assertOk()->viewData('ventas');
        $this->assertContains($this->venta2->id, $listado->pluck('id'));
        $this->assertNotContains($this->venta1->id, $listado->pluck('id'));

        $this->como($this->cajero2)->get(route('ventas.show', $this->venta1))->assertForbidden();
        $this->como($this->cajero2)->get(route('ventas.show', $this->venta2))->assertOk();

        $this->como($this->cajero2)->get(route('comprobantes.index'))->assertOk()
            ->assertDontSee($this->venta1->comprobante->numero_completo)
            ->assertSee($this->venta2->comprobante->numero_completo);
        $this->como($this->cajero2)->get(route('comprobantes.imprimir', $this->venta1->comprobante))->assertForbidden();

        // Los totales de «Mis ventas» también son solo suyos.
        $resumen = $this->como($this->cajero2)->get(route('ventas.index'))->viewData('resumen');
        $this->assertSame((float) DB::table('ventas')->where('usuario_id', $this->cajero2->id)->where('estado', '<>', 'ANULADA')->sum('total'), $resumen['vendido']);
    }

    public function test_cada_cajero_ve_solo_su_turno_y_sus_movimientos_de_caja(): void
    {
        Cajas::movimiento($this->turno1->fresh(), $this->cajero1, 'EGRESO', 'Bolsas del cajero uno', 5);

        $this->como($this->cajero2)->get(route('caja.index'))->assertOk()
            ->assertDontSee(route('caja.show', $this->turno1), false);
        $this->como($this->cajero2)->get(route('caja.show', $this->turno1))->assertForbidden();
        $this->como($this->cajero2)->get(route('caja.show', $this->turno2))->assertOk()
            ->assertDontSee('Bolsas del cajero uno');

        // Tampoco puede registrar movimientos en el turno de otro.
        $this->como($this->cajero2)->post(route('caja.movimiento', $this->turno1), [
            'tipo' => 'EGRESO', 'concepto' => 'Intruso', 'monto' => 1,
        ]);
        $this->assertSame(0, DB::table('movimientos_caja')->where('concepto', 'Intruso')->count());

        Cajas::cerrar($this->turno1->fresh(), $this->admin, $this->turno1->fresh()->efectivoEsperado(), null, 0, $this->turno1->fresh()->huella());
        $this->como($this->cajero2)->get(route('caja.imprimir', $this->turno1))->assertForbidden();
        $this->como($this->cajero1)->get(route('caja.imprimir', $this->turno1))->assertOk();
    }

    public function test_cada_cajero_ve_solo_sus_cobros_por_qr(): void
    {
        $cobro = CobrosQr::generar($this->turno1->fresh(), $this->cajero1, 10);

        $respuesta = $this->como($this->cajero2)->getJson(route('qr.consultar', $cobro));
        $this->assertContains($respuesta->status(), [403, 404]);
        $this->assertSame(CobroQr::PENDIENTE, $cobro->fresh()->estado);
    }

    public function test_el_cajero_no_entra_a_lo_de_todos(): void
    {
        foreach ([route('reportes.ventas'), route('reportes.productos'), route('devoluciones.index'),
            route('bitacora.index'), route('inventario.movimientos')] as $url) {
            $this->como($this->cajero2)->get($url)->assertForbidden();
        }
    }

    // ================================================================ devoluciones

    /** Un rol que registra devoluciones sin ver reportes trabaja solo con sus propias ventas. */
    public function test_las_devoluciones_de_ventas_ajenas_no_se_ven_ni_se_registran(): void
    {
        $encargado = $this->rolCon('Encargado de mostrador', ['ventas.registrar', 'caja.abrir', 'devoluciones.registrar']);
        $turno = Cajas::abrir(Caja::create(['nombre' => 'Caja 3', 'activo' => 1]), $encargado, 50);
        $propia = $this->vender($turno, $encargado);

        $ajena = Devoluciones::registrar($this->venta1->fresh(), $this->admin, $this->turno1->fresh(), [
            ['venta_detalle_id' => $this->venta1->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'Devolución del cajero uno', Devolucion::EFECTIVO);
        $suya = Devoluciones::registrar($propia->fresh(), $encargado, $turno->fresh(), [
            ['venta_detalle_id' => $propia->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'Devolución propia', Devolucion::EFECTIVO);

        $ids = $this->como($encargado)->get(route('devoluciones.index'))->assertOk()->viewData('devoluciones')->pluck('id');
        $this->assertContains($suya->id, $ids);
        $this->assertNotContains($ajena->id, $ids);

        $this->como($encargado)->get(route('devoluciones.show', $ajena))->assertForbidden();
        $this->como($encargado)->get(route('devoluciones.show', $suya))->assertOk();
        $this->como($encargado)->get(route('devoluciones.create', $this->venta2))->assertForbidden();
        $this->como($encargado)->post(route('devoluciones.store', $this->venta2), [
            'motivo' => 'No es mía', 'lineas' => [['venta_detalle_id' => $this->venta2->detalle->first()->id, 'cantidad' => 1]],
        ])->assertForbidden();
        $this->assertSame(0, Devolucion::where('venta_id', $this->venta2->id)->count());

        // El administrador las ve todas.
        $todas = $this->como($this->admin)->get(route('devoluciones.index'))->viewData('devoluciones')->pluck('id');
        $this->assertContains($ajena->id, $todas);
        $this->assertContains($suya->id, $todas);
    }

    // ================================================================ el administrador ve todo

    public function test_el_administrador_ve_todo(): void
    {
        $listado = $this->como($this->admin)->get(route('ventas.index'))->assertOk()->viewData('ventas')->pluck('id');
        $this->assertContains($this->venta1->id, $listado);
        $this->assertContains($this->venta2->id, $listado);

        foreach ([route('ventas.show', $this->venta1), route('ventas.show', $this->venta2),
            route('caja.show', $this->turno1), route('caja.show', $this->turno2),
            route('reportes.ventas'), route('devoluciones.index'), route('bitacora.index'),
            route('inventario.movimientos')] as $url) {
            $this->como($this->admin)->get($url)->assertOk();
        }

        $this->como($this->admin)->get(route('inventario.movimientos'))->assertSee('cajero2');
    }

    // ================================================================ almacenero

    public function test_el_almacenero_no_ve_ventas_cajas_ni_reportes(): void
    {
        foreach ([route('ventas.index'), route('ventas.show', $this->venta1), route('caja.index'),
            route('caja.show', $this->turno1), route('devoluciones.index'), route('comprobantes.index'),
            route('reportes.ventas'), route('reportes.productos'), route('reportes.libro-ventas'),
            route('clientes.index')] as $url) {
            $this->como($this->almacen)->get($url)->assertForbidden();
        }
    }

    /** En el kardex, el almacenero ve que salió por el mostrador, no qué cajero vendió. */
    public function test_el_almacenero_no_ve_quien_vendio_en_el_kardex(): void
    {
        $this->como($this->almacen)->get(route('inventario.movimientos'))->assertOk()
            ->assertSee('Mostrador')
            ->assertDontSee('cajero2')
            ->assertDontSee(route('ventas.show', $this->venta2), false);

        // Filtrar por el cajero no sirve para ver sus ventas.
        $filtrado = $this->como($this->almacen)->get(route('inventario.movimientos', ['usuario' => $this->cajero2->id]))
            ->assertOk()->viewData('movimientos');
        $this->assertSame(0, $filtrado->total());

        $this->como($this->almacen)->get(route('productos.show', Producto::where('codigo', 'P-0004')->firstOrFail()))
            ->assertOk()->assertDontSee('cajero2');
    }

    public function test_el_almacenero_da_de_alta_y_edita_pero_no_elimina(): void
    {
        $categoria = Categoria::firstOrFail();
        $unidad = UnidadMedida::firstOrFail();
        $proveedor = Proveedor::firstOrFail();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->como($this->almacen)->post(route('categorias.store'), ['nombre' => 'Categoría del almacén', 'activo' => 1])
            ->assertSessionHasNoErrors();
        $this->como($this->almacen)->put(route('categorias.update', $categoria), ['nombre' => $categoria->nombre.' (editada)', 'activo' => 1])
            ->assertSessionHasNoErrors();
        $this->assertStringEndsWith('(editada)', $categoria->fresh()->nombre);

        foreach ([
            route('productos.destroy', $producto), route('categorias.destroy', $categoria),
            route('unidades.destroy', $unidad), route('proveedores.destroy', $proveedor),
        ] as $url) {
            $this->como($this->almacen)->delete($url)->assertForbidden();
        }

        $this->assertTrue($producto->fresh()->activo);
        $this->assertNotNull(Categoria::find($categoria->id));

        // Y no ve los botones.
        $this->como($this->almacen)->get(route('productos.show', $producto))->assertOk()->assertDontSee('Eliminar producto');
        foreach (['categorias.index', 'unidades.index', 'proveedores.index'] as $pantalla) {
            $this->como($this->almacen)->get(route($pantalla))->assertOk()->assertDontSee('title="Eliminar"', false);
        }
    }

    // ================================================================ clientes

    public function test_el_cajero_registra_clientes_pero_no_los_edita_ni_elimina(): void
    {
        $this->como($this->cajero2)->postJson(route('clientes.store'), [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '7654321',
            'nombres' => 'Rosa', 'apellidos' => 'Mendoza',
        ])->assertCreated();
        $cliente = Cliente::where('documento', '7654321')->firstOrFail();

        $this->como($this->cajero2)->put(route('clientes.update', $cliente), [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '7654321',
            'nombres' => 'Otro', 'apellidos' => 'Nombre',
        ])->assertForbidden();
        $this->como($this->cajero2)->delete(route('clientes.destroy', $cliente))->assertForbidden();
        $this->assertSame('Rosa', $cliente->fresh()->nombres);

        $this->como($this->cajero2)->get(route('clientes.index'))->assertOk()
            ->assertDontSee('title="Editar"', false)->assertDontSee('title="Eliminar"', false);
    }

    public function test_el_administrador_edita_y_elimina(): void
    {
        $cliente = Cliente::create([
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '7000999',
            'nombres' => 'Para', 'apellidos' => 'Borrar', 'activo' => 1,
        ]);

        $this->como($this->admin)->get(route('clientes.index'))->assertOk()
            ->assertSee('title="Editar"', false)->assertSee('title="Eliminar"', false);
        $this->como($this->admin)->put(route('clientes.update', $cliente), [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '7000999',
            'nombres' => 'Editado', 'apellidos' => 'Borrar',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Editado', $cliente->fresh()->nombres);

        $this->como($this->admin)->delete(route('clientes.destroy', $cliente))->assertSessionHasNoErrors();
        $this->assertNull(Cliente::where('id', $cliente->id)->where('activo', 1)->first());

        $categoria = Categoria::create(['nombre' => 'Temporal para borrar', 'activo' => 1]);
        $this->como($this->admin)->delete(route('categorias.destroy', $categoria))->assertSessionHasNoErrors();
        $this->assertNull(Categoria::find($categoria->id));
    }

    /** Toda ruta que elimina exige el permiso de eliminar, además del de su módulo. */
    public function test_toda_ruta_de_eliminar_pide_el_permiso(): void
    {
        $sinPermiso = [];

        foreach (Route::getRoutes() as $ruta) {
            if (in_array('DELETE', $ruta->methods(), true) && str_ends_with((string) $ruta->getName(), '.destroy')) {
                if (! in_array('permiso:registros.eliminar', $ruta->middleware(), true)) {
                    $sinPermiso[] = $ruta->getName();
                }
            }
        }

        $this->assertSame([], $sinPermiso);
        $this->assertFalse($this->almacen->tienePermiso('registros.eliminar'));
        $this->assertFalse($this->cajero1->tienePermiso('registros.eliminar'));
        $this->assertFalse($this->cajero1->tienePermiso('clientes.editar'));
        $this->assertTrue($this->admin->tienePermiso('registros.eliminar'));
    }
}
