<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Precios con el impuesto incluido: el precio del producto es lo que paga el
 * cliente y el IVA se separa por dentro. Es lo habitual en Bolivia.
 */
class ImpuestoIncluidoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurar(incluido: true, tasa: '0.1300');

        // Precios de estante redondos, como los pondría el negocio.
        Producto::where('codigo', 'P-0004')->update(['precio_venta' => 4.00, 'afecto_impuesto' => 1]);
        Producto::where('codigo', 'P-0009')->update(['precio_venta' => 2.50, 'afecto_impuesto' => 0]);
    }

    private function configurar(bool $incluido, string $tasa): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => $incluido ? '1' : '0']);
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => $tasa]);
        Config::olvidar();
    }

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(float $inicial = 100): SesionCaja
    {
        return Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), $inicial);
    }

    /** @param  array<string, float>  $lineas  código => cantidad */
    private function vender(array $lineas, float $descuento = 0): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->admin(),
            lineas: collect($lineas)->map(fn ($cantidad, $codigo) => [
                'producto_id' => Producto::where('codigo', $codigo)->value('id'),
                'cantidad' => $cantidad,
            ])->values()->all(),
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            descuento: $descuento,
        );
    }

    // ================================================================ la venta

    /** 3 × Bs 4,00 son Bs 12,00: el cliente paga el precio del estante. */
    public function test_el_cliente_paga_el_precio_y_el_iva_va_por_dentro(): void
    {
        $venta = $this->vender(['P-0004' => 3]);
        $linea = $venta->detalle->first();

        $this->assertTrue($venta->impuesto_incluido);
        $this->assertSame('12.00', $venta->total);
        $this->assertSame('1.38', $venta->impuesto);   // 12 × 13 / 113 = 1,3805
        $this->assertSame('10.62', $venta->subtotal);  // la base, sin impuesto
        $this->assertSame('0.00', $venta->descuento);

        $this->assertSame('12.00', (string) $linea->total_linea);
        $this->assertSame('1.38', (string) $linea->impuesto_linea);
        $this->assertSame('10.62', (string) $linea->importe);
    }

    /** El descuento se ve sobre el precio final y el IVA baja en proporción. */
    public function test_el_descuento_se_resta_del_precio_final(): void
    {
        $venta = $this->vender(['P-0004' => 3], descuento: 1.00);

        $this->assertSame('11.00', $venta->total);
        $this->assertSame('1.00', $venta->descuento_precio_final);
        $this->assertSame(1.0, $venta->descuento_visible);
        $this->assertSame(12.0, $venta->total_antes_del_descuento);
        $this->assertSame('1.27', $venta->impuesto);   // 1,38 × 11 / 12 = 1,265
        // La base baja lo que no es impuesto: total = base − descuento + IVA.
        $this->assertSame('0.89', $venta->descuento);
        $this->assertSame(11.0, round((float) $venta->subtotal - (float) $venta->descuento + (float) $venta->impuesto, 2));
    }

    public function test_un_producto_exonerado_no_lleva_iva_adentro(): void
    {
        $venta = $this->vender(['P-0004' => 1, 'P-0009' => 2]);

        $this->assertSame('9.00', $venta->total);      // 4,00 + 2 × 2,50
        $this->assertSame('0.46', $venta->impuesto);   // solo de la leche: 4 × 13 / 113
        $exonerada = $venta->detalle->firstWhere('producto_id', Producto::where('codigo', 'P-0009')->value('id'));
        $this->assertSame('5.00', (string) $exonerada->total_linea);
        $this->assertSame('0.00', (string) $exonerada->impuesto_linea);
    }

    /** Sin IVA (tasa 0), el total es simplemente la suma de los precios. */
    public function test_sin_iva_el_total_es_la_suma_de_los_precios(): void
    {
        $this->configurar(incluido: true, tasa: '0.0000');

        $venta = $this->vender(['P-0004' => 3], descuento: 0.50);

        $this->assertSame('11.50', $venta->total);
        $this->assertSame('0.00', $venta->impuesto);
    }

    /**
     * Cambiar el modo no toca lo ya vendido: cada venta se sigue calculando
     * con el modo con que se hizo.
     */
    public function test_cambiar_el_modo_no_cambia_las_ventas_anteriores(): void
    {
        $incluida = $this->vender(['P-0004' => 3]);

        $this->configurar(incluido: false, tasa: '0.1300');
        $encima = $this->vender(['P-0004' => 3]);

        $this->assertSame('12.00', $incluida->fresh()->total);
        $this->assertFalse($encima->impuesto_incluido);
        $this->assertSame('13.56', $encima->total);    // 12,00 + 13 %
    }

    /**
     * Las cuentas por dentro coinciden al centavo con las de la base, para
     * cualquier importe: PHP (mostrador y modo sin procedimientos) y MySQL.
     */
    public function test_el_iva_por_dentro_da_lo_mismo_en_php_que_en_la_base(): void
    {
        $importes = array_merge(range(1, 400), [4499, 4500, 4501, 99999, 123457]);

        foreach (['0.1300', '0.1500', '0.0300'] as $tasa) {
            $consulta = implode(' UNION ALL ', array_map(
                fn ($c) => 'SELECT '.$c.' AS c, ROUND(('.$c.' / 100) * '.$tasa.' / (1 + '.$tasa.'), 2) AS imp',
                $importes,
            ));

            foreach (DB::select($consulta) as $fila) {
                $this->assertSame(
                    (float) $fila->imp,
                    Config::impuestoDentroDe($fila->c / 100, (float) $tasa),
                    "importe {$fila->c} centavos, tasa {$tasa}",
                );
            }
        }
    }

    // ================================================================ lo que depende

    public function test_la_devolucion_completa_devuelve_lo_cobrado(): void
    {
        $sesion = $this->turno(200);
        $venta = $this->vender(['P-0004' => 3, 'P-0009' => 1], descuento: 0.70);

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion->fresh(),
            $venta->detalle->map(fn ($l) => ['venta_detalle_id' => $l->id, 'cantidad' => (float) $l->cantidad])->all(),
            'El cliente devuelve todo', Devolucion::EFECTIVO);

        $venta->refresh();
        $this->assertSame($venta->total, $venta->total_devuelto);
        $this->assertSame(200.0, $sesion->fresh()->efectivoEsperado());
    }

    public function test_la_ganancia_del_reporte_va_sin_iva(): void
    {
        $antes = $this->actingAs($this->admin())->get(route('reportes.ventas', ['desde' => now()->toDateString(), 'hasta' => now()->toDateString()]))
            ->viewData('resumen');

        $venta = $this->vender(['P-0004' => 3]);

        $despues = $this->actingAs($this->admin())->get(route('reportes.ventas', ['desde' => now()->toDateString(), 'hasta' => now()->toDateString()]))
            ->viewData('resumen');

        $costo = 3 * (float) Producto::where('codigo', 'P-0004')->value('precio_compra');
        $this->assertSame(round(10.62 - $costo, 2), round($despues['ganancia'] - $antes['ganancia'], 2));
        $this->assertSame(12.0, round($despues['vendido'] - $antes['vendido'], 2));
    }

    public function test_el_producto_muestra_el_precio_y_calcula_el_margen_sin_iva(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->assertSame(4.0, $producto->precio_estante);
        $this->assertSame(3.54, $producto->precio_base);   // 4,00 − 0,46
        $this->assertSame(round(3.54 - (float) $producto->precio_compra, 2), $producto->margen);
    }

    public function test_el_ticket_y_el_mostrador_dicen_iva_incluido(): void
    {
        $venta = $this->vender(['P-0004' => 3], descuento: 1.00);

        $this->actingAs($this->admin())->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('data-iva-incluido', false)
            ->assertSeeInOrder(['Productos', Config::importe(12), 'Descuento', Config::importe(1), 'Total', Config::importe(11)]);

        $this->actingAs($this->admin())->get(route('comprobantes.imprimir', $venta->comprobante))->assertOk()
            ->assertSeeInOrder(['Productos', '12.00', 'Descuento', '1.00', 'TOTAL', '11.00', 'IVA incluido', '1.27']);

        $this->actingAs($this->admin())->get(route('pos.index'))->assertOk()->assertSee('IVA incluido (13%)');
    }

    public function test_las_pantallas_de_productos_hablan_de_precio_con_iva(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $this->actingAs($this->admin())->get(route('productos.edit', $producto))->assertOk()
            ->assertSee('Lo que paga el cliente por una unidad, con el IVA incluido.')
            ->assertSee('IVA incluido')
            ->assertDontSee('Precio de venta (base)');

        $this->actingAs($this->admin())->get(route('productos.show', $producto))->assertOk()
            ->assertSee('Precio sin IVA')
            ->assertSee(Config::importe(3.54));

        $this->actingAs($this->admin())->get(route('productos.index'))->assertOk()
            ->assertSee('el precio final con el IVA incluido', false);
    }
}
