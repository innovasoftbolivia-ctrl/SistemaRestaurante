<?php

namespace Tests\Feature;

use App\Http\Controllers\ReporteController;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use App\Services\Inventario;
use App\Services\TomasInventario;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * El inventario de lo que se compra hecho (las bebidas): la venta descuenta,
 * la anulación devuelve, la compra suma y fija el costo, la devolución al
 * proveedor y la toma de inventario ajustan. Y el plato no se toca.
 */
class InventarioTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    /** El refresco, llevando stock: caja de 12, sin stock todavía. */
    private function bebida(float $stock = 0, ?float $costo = 4.00): Producto
    {
        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'stock_minimo' => 6, 'costo' => $costo, 'stock_actual' => 0,
        ])->save();

        if ($stock > 0) {
            Inventario::inicial($p->fresh(), $stock, $this->admin());
        }

        return $p->fresh();
    }

    private function proveedor(): Proveedor
    {
        return Proveedor::create(['razon_social' => 'Distribuidora del Oriente', 'documento' => '1234567']);
    }

    private function vender(array $lineas): Venta
    {
        $turno = Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), 100);

        return Ventas::registrar(
            sesion: $turno->fresh(),
            usuario: $this->admin(),
            lineas: $lineas,
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    public function test_la_venta_descuenta_la_bebida_y_no_el_plato(): void
    {
        $bebida = $this->bebida(24);
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();

        $venta = $this->vender([
            ['producto_id' => $bebida->id, 'cantidad' => 3],
            ['producto_id' => $plato->id, 'cantidad' => 1],
        ]);

        $this->assertSame(21.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame(0.0, (float) $plato->fresh()->stock_actual);

        $mov = MovimientoInventario::where('venta_id', $venta->id)->sole();
        $this->assertSame(['SALIDA', 'VENTA', 24.0, 21.0], [$mov->tipo, $mov->origen, (float) $mov->stock_anterior, (float) $mov->stock_resultante]);

        // El costo quedó congelado en la línea: la ganancia no cambia después.
        $this->assertSame(4.0, (float) $venta->detalle->firstWhere('producto_id', $bebida->id)->costo_unitario);
        $this->assertNull($venta->detalle->firstWhere('producto_id', $plato->id)->costo_unitario);
    }

    /** Sin stock se vende igual: el mostrador no se traba en hora pico. */
    public function test_sin_stock_se_vende_y_queda_negativo(): void
    {
        $bebida = $this->bebida(1);

        $this->vender([['producto_id' => $bebida->id, 'cantidad' => 3]]);

        $this->assertSame(-2.0, (float) $bebida->fresh()->stock_actual);
        $this->assertTrue($bebida->fresh()->bajo_minimo);
    }

    public function test_anular_devuelve_exactamente_lo_que_salio(): void
    {
        $bebida = $this->bebida(10);
        $venta = $this->vender([['producto_id' => $bebida->id, 'cantidad' => 4]]);

        Ventas::anular($venta->fresh(), $this->admin(), 'Se cobró dos veces');

        $this->assertSame(10.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame(1, MovimientoInventario::where('venta_id', $venta->id)->where('origen', 'ANULACION')->count());
    }

    public function test_la_compra_suma_stock_y_fija_el_ultimo_costo(): void
    {
        $bebida = $this->bebida(2, costo: null);

        $compra = Compras::registrar($this->proveedor(), $this->admin(), [
            // 3 cajas de 12 a Bs 54 la caja = 36 unidades a Bs 4.50.
            ['producto_id' => $bebida->id, 'cantidad' => 36, 'costo_unitario' => 4.50],
        ], 'F-001-000123');

        $this->assertSame(38.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame(4.5, (float) $bebida->fresh()->costo);
        $this->assertSame(162.0, $compra->total);
        $this->assertSame('3 cajas y 2 sueltas', $bebida->fresh()->stock_en_empaques);
    }

    public function test_no_se_compra_un_plato(): void
    {
        $this->expectException(RuntimeException::class);

        Compras::registrar($this->proveedor(), $this->admin(), [
            ['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 5, 'costo_unitario' => 10],
        ]);
    }

    public function test_devolucion_con_nota_de_credito_y_su_tope(): void
    {
        $bebida = $this->bebida();
        $compra = Compras::registrar($this->proveedor(), $this->admin(), [
            ['producto_id' => $bebida->id, 'cantidad' => 12, 'costo_unitario' => 4],
        ]);
        $linea = $compra->detalle->first();

        DevolucionesCompra::registrar($compra, $this->admin(), [['compra_detalle_id' => $linea->id, 'cantidad' => 2]], 'DEFECTO', 'NOTA_CREDITO');

        $this->assertSame(10.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame(2.0, (float) $linea->fresh()->cantidad_devuelta);

        // No se devuelve más de lo que llegó.
        $this->expectException(RuntimeException::class);
        DevolucionesCompra::registrar($compra, $this->admin(), [['compra_detalle_id' => $linea->id, 'cantidad' => 11]], 'DEFECTO', 'NOTA_CREDITO');
    }

    public function test_devolucion_repuesta_en_el_acto_no_cambia_el_stock(): void
    {
        $bebida = $this->bebida();
        $compra = Compras::registrar($this->proveedor(), $this->admin(), [
            ['producto_id' => $bebida->id, 'cantidad' => 12, 'costo_unitario' => 4],
        ]);

        $dev = DevolucionesCompra::registrar($compra, $this->admin(), [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 3]], 'DEFECTO', 'REPUESTO');

        $this->assertSame(12.0, (float) $bebida->fresh()->stock_actual);
        $this->assertSame(2, MovimientoInventario::where('devolucion_compra_id', $dev->id)->count());
        $this->assertFalse($dev->fresh('detalle')->debe_reponer);
    }

    public function test_devolucion_pendiente_y_la_reposicion_que_llega_despues(): void
    {
        $bebida = $this->bebida();
        $compra = Compras::registrar($this->proveedor(), $this->admin(), [
            ['producto_id' => $bebida->id, 'cantidad' => 12, 'costo_unitario' => 4],
        ]);

        $dev = DevolucionesCompra::registrar($compra, $this->admin(), [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 5]], 'VENCIMIENTO', 'PENDIENTE');
        $this->assertSame(7.0, (float) $bebida->fresh()->stock_actual);
        $this->assertTrue($dev->fresh('detalle')->debe_reponer);

        $linea = $dev->detalle->first();
        DevolucionesCompra::reponer($dev->fresh(), $this->admin(), [$linea->id => 3]);
        $this->assertSame(10.0, (float) $bebida->fresh()->stock_actual);
        $this->assertTrue($dev->fresh('detalle')->debe_reponer, 'faltan 2 por reponer');

        DevolucionesCompra::reponer($dev->fresh(), $this->admin(), [$linea->id => 2]);
        $this->assertSame(12.0, (float) $bebida->fresh()->stock_actual);
        $this->assertFalse($dev->fresh('detalle')->debe_reponer);
    }

    /** Se cuenta mientras se vende: el cierre aplica la diferencia, no el número contado. */
    public function test_la_toma_aplica_la_diferencia_aunque_se_venda_mientras_se_cuenta(): void
    {
        $bebida = $this->bebida(20);
        $toma = TomasInventario::abrir($this->admin());

        // Se cuentan 18 (faltan 2 botellas: una rotura).
        TomasInventario::contar($toma, $bebida->id, 18, $this->admin());

        // Mientras tanto se venden 3.
        $this->vender([['producto_id' => $bebida->id, 'cantidad' => 3]]);
        $this->assertSame(17.0, (float) $bebida->fresh()->stock_actual);

        $ajustes = TomasInventario::cerrar($toma->fresh(), $this->admin());

        $this->assertSame(1, $ajustes);
        $this->assertSame(15.0, (float) $bebida->fresh()->stock_actual, '20 − 2 de la rotura − 3 vendidas');
        $this->assertSame('CERRADA', $toma->fresh()->estado);
    }

    public function test_una_sola_toma_abierta_y_el_ajuste_olvida_el_conteo(): void
    {
        $bebida = $this->bebida(20);
        $toma = TomasInventario::abrir($this->admin());

        try {
            TomasInventario::abrir($this->admin());
            $this->fail('abrió dos tomas');
        } catch (RuntimeException) {
        }

        TomasInventario::contar($toma, $bebida->id, 18, $this->admin());
        Inventario::ajuste($bebida->fresh(), 16, 'Se rompieron 4', $this->admin());

        // El conteo se olvidó: cerrar no vuelve a restar la misma rotura.
        TomasInventario::cerrar($toma->fresh(), $this->admin());
        $this->assertSame(16.0, (float) $bebida->fresh()->stock_actual);
    }

    /** El reporte del menú dice cuánto deja lo que tiene costo; el plato no sale. */
    public function test_el_reporte_del_menu_muestra_la_ganancia_de_la_bebida(): void
    {
        $bebida = $this->bebida(24);
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();
        $this->vender([
            ['producto_id' => $bebida->id, 'cantidad' => 3],
            ['producto_id' => $plato->id, 'cantidad' => 1],
        ]);

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.productos'))
            ->assertOk()
            ->assertSee('data-ganancias', false);

        $ganancias = ReporteController::ganancias($respuesta->viewData('masVendidos'));
        $this->assertSame([$bebida->id], $ganancias->pluck('id')->all());

        $g = $ganancias->first();
        $this->assertSame(12.0, $g->costo);
        $this->assertGreaterThan(0, $g->vendido);
        $this->assertEqualsWithDelta($g->vendido - 12, $g->ganancia, 0.001);

        $this->actingAs($this->admin())->get(route('reportes.productos.excel'))->assertOk();
    }

    /** La campana avisa a quien gestiona el inventario, y a nadie más. */
    public function test_la_campana_de_stock_avisa_al_administrador(): void
    {
        $this->bebida(2); // mínimo 6: por comprar

        $this->actingAs($this->admin())->get(route('inicio'))
            ->assertOk()
            ->assertSee('data-alertas-stock', false)
            ->assertSee('Por comprar');

        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $this->actingAs($cajero)->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee('data-alertas-stock', false);
    }

    public function test_las_cantidades_se_dicen_en_empaques(): void
    {
        $p = $this->bebida();
        $p->nombre_empaque = 'Caja de 12';

        $this->assertSame('3 cajas de 12 y 2 sueltas', $p->enEmpaques(38));
        $this->assertSame('1 caja de 12', $p->enEmpaques(12));
        $this->assertSame('5 sueltas', $p->enEmpaques(5));
        $this->assertSame('-3 u.', $p->enEmpaques(-3));

        $p->nombre_empaque = 'Pack';
        $this->assertSame('2 packs', $p->enEmpaques(24));
    }
}
