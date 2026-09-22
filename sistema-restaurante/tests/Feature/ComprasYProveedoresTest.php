<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las pantallas de proveedores y compras: quién entra, el alta y la edición
 * del proveedor, y la compra por cajas y sueltas con el costo por caja.
 */
class ComprasYProveedoresTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $usuario): Usuario
    {
        return Usuario::where('usuario', $usuario)->firstOrFail();
    }

    private function admin(): Usuario
    {
        return $this->usuario('admin');
    }

    /** El refresco, llevando stock: caja de 12, sin stock todavía. */
    private function bebida(): Producto
    {
        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'costo' => 4, 'stock_actual' => 0,
        ])->save();

        return $p->fresh();
    }

    private function proveedor(array $datos = []): Proveedor
    {
        return Proveedor::create($datos + ['razon_social' => 'Distribuidora de Prueba', 'documento' => '99887766']);
    }

    public function test_el_admin_ve_las_pantallas_y_los_demas_no(): void
    {
        $bebida = $this->bebida();
        $proveedor = $this->proveedor();
        $compra = Compras::registrar($proveedor, $this->admin(), [
            ['producto_id' => $bebida->id, 'cantidad' => 24, 'costo_unitario' => 4],
        ], 'F-777', 'Llegó completo');
        DevolucionesCompra::registrar($compra, $this->admin(), [
            ['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 2],
        ], 'DEFECTO', 'NOTA_CREDITO');

        $this->actingAs($this->admin());

        $this->get(route('proveedores.index'))->assertOk()->assertSee('Distribuidora de Prueba');
        $this->get(route('proveedores.index', ['buscar' => '99887766', 'estado' => 'activos']))
            ->assertOk()->assertSee('Distribuidora de Prueba');
        $this->get(route('proveedores.index', ['estado' => 'inactivos']))
            ->assertOk()->assertDontSee('Distribuidora de Prueba');

        $this->get(route('compras.index'))->assertOk()->assertSee('F-777');
        $this->get(route('compras.index', [
            'proveedor' => $proveedor->id, 'desde' => now()->toDateString(), 'hasta' => now()->toDateString(),
        ]))->assertOk()->assertSee('F-777');

        $this->get(route('compras.create'))->assertOk()
            ->assertSee('name="_envio"', false)
            ->assertSee($bebida->nombre)
            ->assertSee('Caja de 12');

        $this->get(route('compras.show', $compra))->assertOk()
            ->assertSee('F-777')
            ->assertSee('2 cajas')
            ->assertSee('Devolver al proveedor')
            ->assertSee('Devolución #')
            ->assertSee(route('devoluciones-compra.create', $compra), false);

        foreach (['cajero1', 'cocina1'] as $nombre) {
            $this->actingAs($this->usuario($nombre));
            $this->get(route('proveedores.index'))->assertForbidden();
            $this->get(route('compras.index'))->assertForbidden();
            $this->get(route('compras.create'))->assertForbidden();
            $this->get(route('compras.show', $compra))->assertForbidden();
        }
    }

    public function test_sin_productos_con_inventario_la_pantalla_lo_explica(): void
    {
        Producto::where('controla_stock', 1)->update(['controla_stock' => 0]);

        $this->actingAs($this->admin())
            ->get(route('compras.create'))
            ->assertOk()
            ->assertSee('Lleva inventario')
            ->assertSee(route('productos.index'), false)
            ->assertDontSee('name="_envio"', false);
    }

    public function test_crea_y_edita_un_proveedor(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('proveedores.store'), [
            'razon_social' => 'Embotelladora Andina',
            'documento' => '1023456027',
            'telefono' => '70012345',
            'email' => 'pedidos@andina.bo',
            'direccion' => 'Av. Blanco Galindo km 4',
            'activo' => '1',
        ])->assertRedirect(route('proveedores.index'))->assertSessionHas('exito');

        $proveedor = Proveedor::where('razon_social', 'Embotelladora Andina')->firstOrFail();
        $this->assertTrue($proveedor->activo);

        $this->put(route('proveedores.update', $proveedor), [
            'razon_social' => 'Embotelladora Andina S.A.',
            'documento' => '1023456027',
            'telefono' => '',
            'activo' => '0',
        ])->assertRedirect(route('proveedores.index'));

        $proveedor->refresh();
        $this->assertSame('Embotelladora Andina S.A.', $proveedor->razon_social);
        $this->assertFalse($proveedor->activo);
        $this->assertNull($proveedor->telefono);

        // El NIT son solo números, y no se repite.
        $this->post(route('proveedores.store'), ['razon_social' => 'Otro', 'documento' => 'ABC-1'])
            ->assertSessionHasErrors('documento');
        $this->post(route('proveedores.store'), ['razon_social' => 'Otro', 'documento' => '1023456027'])
            ->assertSessionHasErrors('documento');
        $this->post(route('proveedores.store'), ['razon_social' => 'Embotelladora Andina S.A.'])
            ->assertSessionHasErrors('razon_social');
    }

    public function test_eliminar_un_proveedor_sin_compras_lo_borra_y_con_compras_lo_desactiva(): void
    {
        $this->actingAs($this->admin());

        $libre = $this->proveedor(['razon_social' => 'Sin compras', 'documento' => null]);
        $this->delete(route('proveedores.destroy', $libre))->assertRedirect(route('proveedores.index'));
        $this->assertNull(Proveedor::find($libre->id));

        $conCompras = $this->proveedor();
        Compras::registrar($conCompras, $this->admin(), [
            ['producto_id' => $this->bebida()->id, 'cantidad' => 6, 'costo_unitario' => 4],
        ]);

        $this->delete(route('proveedores.destroy', $conCompras))
            ->assertRedirect(route('proveedores.index'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'desactivó'));

        $this->assertFalse($conCompras->fresh()->activo);
    }

    public function test_la_compra_por_cajas_y_sueltas_con_costo_por_caja_sube_el_stock_y_fija_el_costo(): void
    {
        $bebida = $this->bebida();
        $proveedor = $this->proveedor();

        $respuesta = $this->actingAs($this->admin())->post(route('compras.store'), [
            '_envio' => 'compra-prueba-1',
            'proveedor_id' => $proveedor->id,
            'documento_externo' => 'F-100',
            'observacion' => 'Pedido semanal',
            'lineas' => [
                ['producto_id' => $bebida->id, 'empaques' => 3, 'sueltas' => 5, 'costo' => 60, 'costo_por' => 'empaque'],
            ],
        ]);

        $compra = Compra::where('documento_externo', 'F-100')->firstOrFail();
        $respuesta->assertRedirect(route('compras.show', $compra))->assertSessionHas('exito');

        // 3 cajas de 12 + 5 sueltas = 41 unidades; Bs 60 la caja = Bs 5 la unidad.
        $bebida->refresh();
        $this->assertSame(41.0, (float) $bebida->stock_actual);
        $this->assertSame(5.0, (float) $bebida->costo);

        $linea = $compra->detalle()->firstOrFail();
        $this->assertSame(41.0, (float) $linea->cantidad);
        $this->assertSame(5.0, (float) $linea->costo_unitario);
        $this->assertSame(205.0, (float) $linea->importe);
        $this->assertSame($proveedor->id, $compra->proveedor_id);
        $this->assertSame('Pedido semanal', $compra->observacion);
    }

    public function test_el_costo_por_unidad_se_guarda_tal_cual(): void
    {
        $bebida = $this->bebida();

        $this->actingAs($this->admin())->post(route('compras.store'), [
            '_envio' => 'compra-prueba-2',
            'proveedor_id' => $this->proveedor()->id,
            'lineas' => [
                ['producto_id' => $bebida->id, 'empaques' => 1, 'sueltas' => 0, 'costo' => 4.5, 'costo_por' => 'unidad'],
            ],
        ])->assertSessionHasNoErrors();

        $bebida->refresh();
        $this->assertSame(12.0, (float) $bebida->stock_actual);
        $this->assertSame(4.5, (float) $bebida->costo);
    }

    public function test_la_compra_sin_lineas_o_con_cantidad_cero_no_se_registra(): void
    {
        $bebida = $this->bebida();
        $proveedor = $this->proveedor();
        $this->actingAs($this->admin());

        $this->from(route('compras.create'))->post(route('compras.store'), [
            '_envio' => 'compra-vacia',
            'proveedor_id' => $proveedor->id,
            'lineas' => [],
        ])->assertRedirect(route('compras.create'))->assertSessionHasErrors('lineas');

        $this->from(route('compras.create'))->post(route('compras.store'), [
            '_envio' => 'compra-cero',
            'proveedor_id' => $proveedor->id,
            'lineas' => [
                ['producto_id' => $bebida->id, 'empaques' => 0, 'sueltas' => 0, 'costo' => 5, 'costo_por' => 'unidad'],
            ],
        ])->assertSessionHasErrors('lineas.0.sueltas');

        // Un plato no lleva inventario: no se compra.
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        $this->from(route('compras.create'))->post(route('compras.store'), [
            '_envio' => 'compra-plato',
            'proveedor_id' => $proveedor->id,
            'lineas' => [
                ['producto_id' => $plato->id, 'sueltas' => 2, 'costo' => 5, 'costo_por' => 'unidad'],
            ],
        ])->assertSessionHasErrors('lineas.0.producto_id');

        $this->assertSame(0, Compra::where('proveedor_id', $proveedor->id)->count());
        $this->assertSame(0.0, (float) $bebida->fresh()->stock_actual);

        // Con los errores, el formulario vuelve con lo escrito.
        $this->withSession(['_old_input' => [
            'proveedor_id' => $proveedor->id,
            'lineas' => [['producto_id' => $bebida->id, 'empaques' => 2, 'sueltas' => 1, 'costo' => 60, 'costo_por' => 'empaque']],
        ]])->get(route('compras.create'))->assertOk();
    }

    public function test_un_proveedor_desactivado_no_recibe_compras(): void
    {
        $bebida = $this->bebida();
        $proveedor = $this->proveedor();
        $proveedor->update(['activo' => false]);

        $this->actingAs($this->admin())->post(route('compras.store'), [
            '_envio' => 'compra-inactivo',
            'proveedor_id' => $proveedor->id,
            'lineas' => [['producto_id' => $bebida->id, 'sueltas' => 6, 'costo' => 4, 'costo_por' => 'unidad']],
        ])->assertSessionHasErrors('proveedor_id');
    }
}
