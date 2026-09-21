<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Mensaje;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Los menores de la auditoría final (17/09/2026), cada uno con lo que pasaba.
 */
class MenoresDeAuditoriaFinalTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** Cambiar de cuenta exige sesión limpia (`auth.session` manda al login). */
    private function como(Usuario $usuario): static
    {
        $this->flushSession();
        app('auth')->forgetGuards();

        return $this->actingAs($usuario);
    }

    private function turno(Usuario $usuario, ?Caja $caja = null): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir($caja ?? Caja::orderBy('id')->firstOrFail(), $usuario, 100))->fresh();
    }

    private function vender(Usuario $cajero, SesionCaja $turno, ?Cliente $cliente = null): Venta
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        return Ventas::registrar(
            sesion: $turno->fresh(),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    private function otroCajero(): Usuario
    {
        return Usuario::create([
            'empleado_id' => 4,
            'rol_id' => $this->u('cajero1')->rol_id,
            'usuario' => 'cajero2',
            'password_hash' => Hash::make('cajero2-clave'),
            'debe_cambiar_password' => 0,
            'activo' => 1,
        ]);
    }

    private function productoNuevo(Categoria $categoria): Producto
    {
        return Producto::create([
            'categoria_id' => $categoria->id,
            'codigo' => 'P-9933',
            'nombre' => 'Producto recién creado',
            'precio_venta' => '8.00',
            'afecto_impuesto' => 1,
            'activo' => 1,
        ]);
    }

    // ============================================================ 1. borrar un producto con historial

    /** Quien decide es la clave foránea: un producto que ya se vendió no se puede borrar. */
    public function test_borrar_un_producto_recien_creado_pero_ya_vendido_lo_descataloga(): void
    {
        $categoria = Categoria::create(['nombre' => 'Categoría nueva', 'activo' => 1]);
        $producto = $this->productoNuevo($categoria);
        $cajero = $this->u('cajero1');

        Ventas::registrar(
            sesion: $this->turno($cajero),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        $this->como($this->u('admin'))->delete(route('productos.destroy', $producto))
            ->assertRedirect(route('productos.index'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'se retiró del menú'));

        $this->assertFalse((bool) $producto->fresh()->activo);
    }

    public function test_un_producto_sin_historial_se_sigue_eliminando(): void
    {
        $producto = $this->productoNuevo(Categoria::firstOrFail());

        $this->como($this->u('admin'))->delete(route('productos.destroy', $producto))
            ->assertRedirect(route('productos.index'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'se quitó del menú'));

        $this->assertNull(Producto::find($producto->id));
    }

    // ============================================================ 4. errores de la base en pantalla

    public function test_un_monto_con_milesimas_se_rechaza_en_el_formulario(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);

        $this->como($cajero)->post(route('caja.movimiento', $turno), [
            'tipo' => 'INGRESO', 'concepto' => 'Cambio', 'monto' => '0.004',
        ])->assertSessionHasErrors('monto');

        $this->assertSame(0, $turno->movimientos()->count());
    }

    public function test_un_error_de_la_base_no_llega_a_la_pantalla_con_la_consulta(): void
    {
        $crudo = new QueryException('mysql', 'insert into movimientos_caja (monto) values (?)', [0],
            new PDOException('SQLSTATE[HY000]: General error: 3819 Check constraint ck_mov_monto is violated.'));
        $mensaje = Mensaje::de($crudo);
        $this->assertStringNotContainsString('insert into', $mensaje);
        $this->assertStringNotContainsString('SQLSTATE', $mensaje);
        $this->assertSame(Mensaje::GENERICO, $mensaje);

        $signal = new QueryException('mysql', 'call sp_x()', [],
            new PDOException('SQLSTATE[45000]: <<Unknown error>>: 1644 La venta no existe'));
        $this->assertSame('La venta no existe', Mensaje::de($signal));

        $this->assertSame('Texto del servicio', Mensaje::de(new RuntimeException('Texto del servicio')));
    }

    public function test_ningun_controlador_muestra_el_mensaje_crudo_de_una_runtime_exception(): void
    {
        foreach (glob(app_path('Http/Controllers/*.php')) as $archivo) {
            $codigo = file_get_contents($archivo);
            preg_match_all('/catch \(RuntimeException \$e\) \{[^}]*\}/', $codigo, $bloques);
            foreach ($bloques[0] as $bloque) {
                $this->assertStringNotContainsString('$e->getMessage()', $bloque, basename($archivo));
            }
        }
    }

    // ============================================================ 5. compras de un cliente

    public function test_el_cajero_ve_cuantas_veces_le_compro_el_cliente_a_el(): void
    {
        $cajero1 = $this->u('cajero1');
        $cajero2 = $this->otroCajero();
        $cliente = Cliente::create([
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '7000888',
            'nombres' => 'Cliente', 'apellidos' => 'Compartido', 'activo' => 1,
        ]);
        $segunda = Caja::create(['nombre' => 'Caja 2', 'ubicacion' => 'Pasillo', 'activo' => 1]);
        $turno1 = $this->turno($cajero1);
        $turno2 = $this->turno($cajero2, $segunda);

        $this->vender($cajero1, $turno1, $cliente);
        $this->vender($cajero2, $turno2, $cliente);
        $this->vender($cajero2, $turno2, $cliente);

        $cuenta = fn (Usuario $u) => $this->como($u)->get(route('clientes.index', ['buscar' => '7000888']))
            ->assertOk()->viewData('clientes')->firstWhere('id', $cliente->id)->ventas_count;

        $this->assertSame(1, (int) $cuenta($cajero1));
        $this->assertSame(3, (int) $cuenta($this->u('admin')));
    }

    // ============================================================ 8. rol a medida con anular

    public function test_un_rol_con_anular_sin_ver_todo_no_anula_ni_sustituye_ventas_ajenas(): void
    {
        $rol = Rol::create(['nombre' => 'Supervisor de prueba', 'activo' => 1]);
        $rol->permisos()->sync(Permiso::whereIn('codigo', ['ventas.ver', 'ventas.anular', 'pos.vender', 'caja.abrir'])->pluck('id'));
        $empleado = DB::table('empleados')->insertGetId([
            'cargo_id' => 1, 'tipo_documento' => 'CI', 'documento' => '28888111',
            'nombres' => 'Supervisor', 'apellidos' => 'De Prueba', 'fecha_ingreso' => now()->toDateString(),
            'tipo_contrato' => 'INDEFINIDO', 'estado' => 'ACTIVO',
        ]);
        $supervisor = Usuario::create([
            'empleado_id' => $empleado, 'rol_id' => $rol->id, 'usuario' => 'supervisor',
            'password_hash' => Hash::make('clave-de-prueba'), 'debe_cambiar_password' => 0, 'activo' => 1,
        ]);

        $cajero = $this->u('cajero1');
        $venta = $this->vender($cajero, $this->turno($cajero));

        $this->como($supervisor)->post(route('ventas.anular', $venta), ['motivo_anulacion' => 'Intento ajeno'])
            ->assertForbidden();
        $this->assertSame('COMPLETADA', $venta->fresh()->estado);

        $comprobante = $venta->comprobantes()->where('estado', 'EMITIDO')->firstOrFail();
        $this->como($supervisor)->post(route('comprobantes.sustituir', $comprobante), ['motivo' => 'Intento ajeno'])
            ->assertForbidden();
        $this->assertSame('EMITIDO', $comprobante->fresh()->estado);
    }

    // ============================================================ 9. x-data del cliente

    public function test_el_cliente_del_formulario_de_sustitucion_va_escapado_para_javascript(): void
    {
        $admin = $this->u('admin');
        $venta = $this->vender($admin, $this->turno($admin));

        $html = $this->como($admin)->withSession(['_old_input' => ['cliente_id' => "1'+alert(1)+'"]])
            ->get(route('ventas.show', $venta))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="{ cliente: ', $html);
        $this->assertStringNotContainsString('&#039;+alert(1)+&#039;', $html);
        $this->assertStringNotContainsString("'+alert(1)+'", $html);
    }
}
