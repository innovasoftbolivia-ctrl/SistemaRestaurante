<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\CompraSugerida;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La compra sugerida: lo que está en su mínimo, hasta el mínimo más una
 * semana de venta, en cajas cerradas y por el último proveedor.
 */
class CompraSugeridaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    /**
     * El refresco en caja de 12, mínimo 6: se compraron 16 a la distribuidora
     * y se vendieron 14 en la quincena (7 por semana). Quedan 2.
     */
    private function escenario(): array
    {
        $bebida = Producto::where('codigo', 'P-0005')->firstOrFail();
        $bebida->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'stock_minimo' => 6, 'costo' => 4, 'stock_actual' => 0,
        ])->save();

        $proveedor = Proveedor::create(['razon_social' => 'Distribuidora del Sur', 'documento' => '4455667']);
        Compras::registrar($proveedor, $this->admin(), [
            ['producto_id' => $bebida->id, 'cantidad' => 16, 'costo_unitario' => 4],
        ]);

        $turno = Cajas::abrir(Caja::firstOrFail(), $this->admin(), 100);
        Ventas::registrar(
            sesion: $turno->fresh(), usuario: $this->admin(),
            lineas: [['producto_id' => $bebida->id, 'cantidad' => 14]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        // Lleva inventario, en unidades, y nunca se compró: sin proveedor.
        $suelto = Producto::where('codigo', 'P-0003')->firstOrFail();
        $suelto->forceFill([
            'controla_stock' => true, 'contenido_empaque' => null, 'nombre_empaque' => null,
            'stock_minimo' => 5, 'costo' => null, 'stock_actual' => 0,
        ])->save();

        return [$bebida->fresh(), $proveedor, $suelto->fresh()];
    }

    public function test_sugiere_hasta_el_minimo_mas_una_semana_en_cajas_cerradas(): void
    {
        [$bebida, $proveedor, $suelto] = $this->escenario();
        $this->assertSame(2.0, (float) $bebida->stock_actual);

        $grupos = CompraSugerida::porProveedor();

        // Al último proveedor: 6 de mínimo + 7 por semana = 13; faltan 11 → 1 caja.
        $delProveedor = $grupos->first(fn ($g) => $g['proveedor']?->id === $proveedor->id);
        $linea = $delProveedor['lineas']->firstWhere('producto.id', $bebida->id);
        $this->assertSame(7.0, $linea['por_semana']);
        $this->assertSame(1, $linea['empaques']);
        $this->assertSame(12.0, (float) $linea['cantidad']);
        $this->assertSame(48.0, $delProveedor['total']);

        // Sin ventas ni proveedor: hasta el doble del mínimo, en unidades, aparte.
        $sinProveedor = $grupos->last();
        $this->assertNull($sinProveedor['proveedor']);
        $this->assertSame(10.0, (float) $sinProveedor['lineas']->firstWhere('producto.id', $suelto->id)['cantidad']);
    }

    public function test_la_compra_se_arma_llena_y_al_guardarla_sale_de_la_sugerencia(): void
    {
        [$bebida, $proveedor] = $this->escenario();

        $this->actingAs($this->admin())->get(route('compras.sugerida'))
            ->assertOk()
            ->assertSee('Distribuidora del Sur')
            ->assertSee('Sin proveedor anterior')
            ->assertSee(route('compras.create', ['sugerida' => $proveedor->id]), false);

        $formulario = $this->actingAs($this->admin())->get(route('compras.create', ['sugerida' => $proveedor->id]))->assertOk();
        $this->assertSame($proveedor->id, $formulario->viewData('proveedorSugerido'));
        $lineas = $formulario->viewData('lineasSugeridas');
        $this->assertSame([[
            'producto_id' => (string) $bebida->id, 'empaques' => '1', 'sueltas' => '', 'costo' => '4.00', 'costo_por' => 'unidad',
        ]], $lineas);

        $this->actingAs($this->admin())->post(route('compras.store'), [
            '_envio' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'lineas' => $lineas,
        ])->assertSessionHasNoErrors();

        $this->assertSame(14.0, (float) $bebida->fresh()->stock_actual);
        $this->assertFalse(CompraSugerida::porProveedor()->contains(fn ($g) => $g['proveedor']?->id === $proveedor->id));
    }

    public function test_sin_nada_bajo_el_minimo_lo_dice(): void
    {
        Producto::query()->update(['controla_stock' => false]);

        $this->actingAs($this->admin())->get(route('compras.sugerida'))
            ->assertOk()->assertSee('data-sugerida-vacia', false)->assertSee('No hace falta comprar nada');
    }

    public function test_el_cajero_no_entra(): void
    {
        $this->actingAs(Usuario::where('usuario', 'cajero1')->firstOrFail())
            ->get(route('compras.sugerida'))->assertForbidden();
    }
}
