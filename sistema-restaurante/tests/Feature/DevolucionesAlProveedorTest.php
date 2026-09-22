<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las pantallas de devoluciones al proveedor: devolver contra una compra,
 * con sus tres finales, y registrar la reposición que llega después.
 */
class DevolucionesAlProveedorTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $usuario): Usuario
    {
        return Usuario::where('usuario', $usuario)->firstOrFail();
    }

    private function bebida(): Producto
    {
        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill(['controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja', 'costo' => 4])->save();

        return $p->fresh();
    }

    /** Llegan 24 unidades de la bebida. */
    private function compra(): Compra
    {
        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora X']);

        return Compras::registrar($proveedor, $this->u('admin'), [
            ['producto_id' => $this->bebida()->id, 'cantidad' => 24, 'costo_unitario' => 4],
        ])->fresh('detalle');
    }

    private function stock(): float
    {
        return (float) Producto::where('codigo', 'P-0005')->value('stock_actual');
    }

    private function devolver(Compra $compra, float $cantidad, string $espera): TestResponse
    {
        return $this->actingAs($this->u('admin'))->post(route('devoluciones-compra.store', $compra), [
            '_envio' => (string) Str::uuid(),
            'cantidades' => [$compra->detalle->first()->id => $cantidad],
            'motivo' => 'DEFECTO',
            'espera' => $espera,
            'documento_externo' => 'ND-100',
            'observacion' => 'Botellas rotas',
        ]);
    }

    public function test_el_administrador_ve_las_tres_pantallas(): void
    {
        $compra = $this->compra();
        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('devoluciones-compra.create', $compra))
            ->assertOk()
            ->assertSee('Distribuidora X')
            ->assertSee('Traerá el reemplazo')
            ->assertSee('el stock baja hoy y sube cuando llegue')
            ->assertSee('name="_envio"', false);

        $devolucion = DevolucionesCompra::registrar($compra, $admin, [
            ['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 3],
        ], 'DEFECTO', 'PENDIENTE');

        $this->actingAs($admin)->get(route('devoluciones-compra.index'))
            ->assertOk()
            ->assertSee('Devolución #'.$devolucion->id)
            ->assertSee('Distribuidora X');

        $this->actingAs($admin)->get(route('devoluciones-compra.index', ['debe' => 1, 'espera' => 'PENDIENTE']))
            ->assertOk()
            ->assertSee('Devolución #'.$devolucion->id);

        $this->actingAs($admin)->get(route('devoluciones-compra.index', ['espera' => 'REPUESTO']))
            ->assertOk()
            ->assertDontSee('Devolución #'.$devolucion->id);

        $this->actingAs($admin)->get(route('devoluciones-compra.show', $devolucion))
            ->assertOk()
            ->assertSee('Registrar lo que llegó')
            ->assertSee('name="_envio"', false);
    }

    public function test_el_cajero_y_la_cocina_no_entran(): void
    {
        $compra = $this->compra();
        $devolucion = DevolucionesCompra::registrar($compra, $this->u('admin'), [
            ['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 1],
        ], 'DEFECTO', 'NOTA_CREDITO');

        foreach (['cajero1', 'cocina1'] as $usuario) {
            $quien = $this->u($usuario);
            $this->actingAs($quien)->get(route('devoluciones-compra.index'))->assertForbidden();
            $this->actingAs($quien)->get(route('devoluciones-compra.create', $compra))->assertForbidden();
            $this->actingAs($quien)->get(route('devoluciones-compra.show', $devolucion))->assertForbidden();
        }
    }

    public function test_la_nota_de_credito_baja_el_stock(): void
    {
        $compra = $this->compra();
        $this->assertSame(24.0, $this->stock());

        $this->devolver($compra, 5, 'NOTA_CREDITO')
            ->assertRedirect()
            ->assertSessionHas('exito');

        $devolucion = DevolucionCompra::where('compra_id', $compra->id)->firstOrFail();
        $this->assertSame(19.0, $this->stock());
        $this->assertSame('ND-100', $devolucion->documento_externo);
        $this->assertSame(20.0, $devolucion->total);
        $this->assertSame(5.0, (float) $compra->detalle->first()->fresh()->cantidad_devuelta);
    }

    public function test_devolver_mas_de_lo_que_llego_no_cambia_nada(): void
    {
        $compra = $this->compra();

        $this->actingAs($this->u('admin'))->get(route('devoluciones-compra.create', $compra));
        $this->devolver($compra, 30, 'NOTA_CREDITO')
            ->assertRedirect(route('devoluciones-compra.create', $compra))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'hasta 24'));

        $this->assertSame(24.0, $this->stock());
        $this->assertSame(0, DevolucionCompra::where('compra_id', $compra->id)->count());
        $this->assertSame(0.0, (float) $compra->detalle->first()->fresh()->cantidad_devuelta);
    }

    public function test_sin_nada_por_devolver_vuelve_a_la_compra(): void
    {
        $compra = $this->compra();
        $this->devolver($compra, 24, 'NOTA_CREDITO')->assertSessionHas('exito');

        $this->actingAs($this->u('admin'))->get(route('devoluciones-compra.create', $compra))
            ->assertRedirect(route('compras.show', $compra))
            ->assertSessionHas('error');
    }

    public function test_pendiente_y_su_reposicion_en_partes(): void
    {
        $compra = $this->compra();

        $this->devolver($compra, 6, 'PENDIENTE')->assertSessionHas('exito');
        $this->assertSame(18.0, $this->stock());

        $devolucion = DevolucionCompra::where('compra_id', $compra->id)->firstOrFail();
        $linea = $devolucion->detalle->first();
        $admin = $this->u('admin');

        // Llega una parte.
        $this->actingAs($admin)->post(route('devoluciones-compra.reponer', $devolucion), [
            '_envio' => (string) Str::uuid(),
            'cantidades' => [$linea->id => 4],
        ])->assertRedirect(route('devoluciones-compra.show', $devolucion))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'todavía debe 2'));

        $this->assertSame(22.0, $this->stock());
        $this->assertTrue($devolucion->fresh()->debe_reponer);

        // Pedir más de lo que debe no hace nada.
        $this->actingAs($admin)->post(route('devoluciones-compra.reponer', $devolucion), [
            '_envio' => (string) Str::uuid(),
            'cantidades' => [$linea->id => 5],
        ])->assertSessionHas('error');
        $this->assertSame(22.0, $this->stock());

        // Llega el resto.
        $this->actingAs($admin)->post(route('devoluciones-compra.reponer', $devolucion), [
            '_envio' => (string) Str::uuid(),
            'cantidades' => [$linea->id => 2],
        ])->assertSessionHas('exito', fn ($m) => str_contains($m, 'ya no debe nada'));

        $this->assertSame(24.0, $this->stock());
        $this->assertFalse($devolucion->fresh()->debe_reponer);

        // Ya no se ofrece el formulario de reposición.
        $this->actingAs($admin)->get(route('devoluciones-compra.show', $devolucion))
            ->assertOk()
            ->assertDontSee('Registrar lo que llegó');
    }

    public function test_repuesto_en_el_acto_deja_el_stock_igual(): void
    {
        $compra = $this->compra();

        $this->devolver($compra, 3, 'REPUESTO')->assertSessionHas('exito');

        $this->assertSame(24.0, $this->stock());
        $devolucion = DevolucionCompra::where('compra_id', $compra->id)->firstOrFail();
        $this->assertFalse($devolucion->debe_reponer);

        $this->actingAs($this->u('admin'))->get(route('devoluciones-compra.show', $devolucion))
            ->assertOk()
            ->assertSee('Lo cambió en el momento')
            ->assertDontSee('Registrar lo que llegó');
    }
}
