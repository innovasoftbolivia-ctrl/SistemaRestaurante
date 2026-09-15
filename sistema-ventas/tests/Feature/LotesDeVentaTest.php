<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Lote;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Services\Inventario;
use App\Services\Lotes;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Lotes y ventas: por dónde sale la mercadería y adónde vuelve.
 *
 * Cada prueba arma sus propios lotes sobre P-0004 (se vende por unidad) y deja
 * el stock igual a la suma de los lotes, que es la regla del sistema.
 */
class LotesDeVentaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function producto(): Producto
    {
        return Producto::where('codigo', 'P-0004')->firstOrFail();
    }

    /**
     * @param  array<string, array{0: int, 1: float}>  $lotes  nombre => [días hasta vencer, cantidad]
     * @return array<string, Lote>
     */
    private function lotes(array $lotes): array
    {
        $producto = $this->producto();
        DB::table('lotes')->where('producto_id', $producto->id)->delete();
        $producto->forceFill(['controla_vencimiento' => 1, 'stock_actual' => array_sum(array_column($lotes, 1))])->save();

        return array_map(fn (array $l) => Lote::create([
            'producto_id' => $producto->id,
            'fecha_vencimiento' => now()->addDays($l[0])->toDateString(),
            'cantidad_inicial' => $l[1],
            'cantidad_actual' => $l[1],
        ]), $lotes);
    }

    private function vender(float $cantidad): Venta
    {
        $sesion = Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), 500);

        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $this->admin(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => $cantidad]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    private function cantidad(Lote $lote): float
    {
        return (float) $lote->fresh()->cantidad_actual;
    }

    // ================================================================ salida

    /** Lo vencido está retirado del estante: la venta sale de lo vigente. */
    public function test_la_venta_no_descuenta_primero_del_lote_vencido(): void
    {
        ['vencido' => $vencido, 'bueno' => $bueno] = $this->lotes(['vencido' => [-5, 8], 'bueno' => [180, 50]]);

        $this->vender(10);

        $this->assertSame(8.0, $this->cantidad($vencido), 'la alerta de vencidos se habría apagado sola');
        $this->assertSame(40.0, $this->cantidad($bueno));
    }

    // ================================================================ vuelta

    /** La anulación devuelve cada unidad al lote del que salió, con su fecha. */
    public function test_la_anulacion_repone_al_lote_del_que_salio(): void
    {
        ['pronto' => $pronto, 'tarde' => $tarde] = $this->lotes(['pronto' => [6, 5], 'tarde' => [365, 50]]);
        $venta = $this->vender(5);
        $this->assertSame(0.0, $this->cantidad($pronto));

        Ventas::anular($venta->fresh(), $this->admin(), 'Error de cobro');

        $this->assertSame(5.0, $this->cantidad($pronto), 'lo que vence en 6 días tenía que volver a su lote');
        $this->assertSame(50.0, $this->cantidad($tarde));
    }

    public function test_la_devolucion_parcial_repone_primero_al_ultimo_lote_del_que_salio(): void
    {
        ['pronto' => $pronto, 'tarde' => $tarde] = $this->lotes(['pronto' => [6, 5], 'tarde' => [365, 50]]);
        $venta = $this->vender(8);                          // 5 del que vence pronto y 3 del otro

        Devoluciones::registrar($venta->fresh(), $this->admin(), Cajas::sesionDe($this->admin()),
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2, 'reingresa_stock' => true]], 'Sobraron dos');

        $this->assertSame(0.0, $this->cantidad($pronto));
        $this->assertSame(49.0, $this->cantidad($tarde));
        $this->assertSame($this->cantidad($pronto) + $this->cantidad($tarde), (float) $this->producto()->stock_actual);
    }

    // ================================================================ control apagado

    public function test_con_el_control_apagado_un_lote_no_se_da_de_baja_ni_avisa(): void
    {
        ['vencido' => $vencido] = $this->lotes(['vencido' => [-3, 5], 'bueno' => [90, 20]]);
        $this->producto()->forceFill(['controla_vencimiento' => 0])->save();
        $stock = (float) $this->producto()->stock_actual;
        $this->actingAs($this->admin());

        try {
            Inventario::bajaDeLote($vencido->fresh(), 'Vencido');
            $this->fail('se dio de baja un lote de un producto sin control de vencimiento');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no controla vencimiento', $e->getMessage());
        }

        $this->assertSame($stock, (float) $this->producto()->stock_actual);
        $this->assertSame(0, Lotes::alertas()->where('p.id', $this->producto()->id)->count());
    }

    /** Lo vendido con el control apagado no descontó lotes: al encenderlo se recortan. */
    public function test_al_volver_a_encender_el_control_se_recortan_los_lotes_fantasma(): void
    {
        $this->lotes(['a' => [30, 20], 'b' => [90, 30]]);
        $this->producto()->forceFill(['controla_vencimiento' => 0, 'stock_actual' => 10])->save();

        $this->producto()->forceFill(['controla_vencimiento' => 1])->save();
        Lotes::cuadrarConElStock($this->producto());

        $this->assertSame(10.0, (float) Lote::where('producto_id', $this->producto()->id)->sum('cantidad_actual'));
    }
}
