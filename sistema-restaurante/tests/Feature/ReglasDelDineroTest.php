<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Services\ReglasEnPhp;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Las reglas del dinero que tienen que vivir en un solo lugar: el tope de
 * descuento en la puerta de la venta, una sola definición de «efectivo», y la
 * base y PHP diciendo lo mismo (auditoría de normalización, bloque 1).
 */
class ReglasDelDineroTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** El turno de quien vende, en una caja libre: cada prueba usa un solo cajero. */
    private function turno(Usuario $usuario): SesionCaja
    {
        $libre = Caja::whereDoesntHave('sesiones', fn ($q) => $q->where('estado', 'ABIERTA'))->orderBy('id')->first()
            ?? Caja::create(['nombre' => 'Caja de la prueba', 'activo' => 1]);

        return (Cajas::sesionDe($usuario) ?? Cajas::abrir($libre, $usuario, 100))->fresh();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    private function plato(string $codigo = 'P-0010'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function vender(Usuario $usuario, float $descuento, array $pagos = []): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno($usuario),
            usuario: $usuario,
            lineas: [['producto_id' => $this->plato()->id, 'cantidad' => 2]],
            pagos: $pagos ?: [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
            descuento: $descuento,
        );
    }

    // ============================================================ C2 · el tope

    /**
     * El tope de descuento vivía en los dos controladores —con dos cálculos
     * distintos y antes de la transacción—: cualquier otro camino hasta
     * `Ventas::registrar()` lo saltaba. Ahora lo exige el servicio.
     */
    public function test_el_tope_de_descuento_lo_exige_la_venta_misma(): void
    {
        $cajero = $this->usuario('cajero1');
        $antes = Venta::count();

        $this->assertThrows(fn () => $this->vender($cajero, 5.00), RuntimeException::class, 'supera el máximo');
        $this->assertSame($antes, Venta::count());

        // Con el permiso `ventas.descuento`, pasa.
        $venta = $this->vender($this->usuario('admin'), 5.00);
        $this->assertSame('5.00', $venta->fresh()->descuento);
    }

    /** La base es la venta que de verdad se registra: el máximo exacto pasa, un céntimo más no. */
    public function test_el_tope_se_mide_contra_el_subtotal_real_de_la_venta(): void
    {
        $cajero = $this->usuario('cajero1');
        $subtotal = round(2 * (float) $this->plato()->precio_venta, 2);
        $maximo = (int) Config::get('descuento_max_cajero');
        $justo = floor($subtotal * $maximo) / 100;

        $venta = $this->vender($cajero, $justo)->fresh();
        $this->assertSame(number_format($justo, 2, '.', ''), $venta->descuento);

        $this->assertThrows(fn () => $this->vender($cajero, $justo + 0.02), RuntimeException::class, 'supera el máximo');
    }

    /** Con el impuesto incluido el cliente ve el descuento sobre el total: esa es la base. */
    public function test_con_impuesto_incluido_el_tope_se_mide_sobre_el_total(): void
    {
        DB::table('configuracion')->where('clave', 'precios_incluyen_impuesto')->update(['valor' => '1']);
        Config::olvidar();

        $cajero = $this->usuario('cajero1');
        $total = round(2 * (float) $this->plato()->precio_venta, 2);
        $justo = floor($total * (int) Config::get('descuento_max_cajero')) / 100;

        $venta = $this->vender($cajero, $justo)->fresh();
        $this->assertTrue($venta->impuesto_incluido);
        $this->assertSame($justo, (float) $venta->descuento_precio_final);

        $this->assertThrows(fn () => $this->vender($cajero, $justo + 0.02), RuntimeException::class, 'supera el máximo');
    }

    /** Volver a cobrar un pedido pasa por la misma puerta: tampoco se salta el tope. */
    public function test_volver_a_cobrar_un_pedido_respeta_el_tope(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turno($cajero);
        $venta = Pedidos::venderEnMostrador($turno, $cajero, [['producto_id' => $this->plato()->id, 'cantidad' => 2]],
            [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]]);
        $pedido = $venta->pedido;
        Ventas::anular($venta->fresh(), $this->usuario('admin'), 'Se cobró con la forma equivocada');

        $this->assertThrows(
            fn () => Pedidos::cobrar($pedido->fresh(), $turno->fresh(), $cajero, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]], descuento: 5.00),
            RuntimeException::class,
            'supera el máximo',
        );

        $this->actingAs($cajero)->post(route('pedidos.cobrar.store', $pedido), [
            'descuento' => '5.00',
            'pagos' => [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
        ])->assertSessionHas('error', fn (string $m) => str_contains($m, 'supera el máximo'));

        $this->assertTrue($pedido->fresh()->estaAbierto());
    }

    // ================================================= A4 · qué es «efectivo»

    /**
     * Una sola verdad: lo que entra al cajón (`afecta_caja`) es lo que admite
     * monto recibido y vuelto. Antes el arqueo contaba por `afecta_caja` y el
     * vuelto miraba el código `EFECTIVO`: un segundo método de caja entraba al
     * arqueo pero no daba vuelto.
     */
    public function test_el_vuelto_lo_admite_lo_que_entra_al_cajon(): void
    {
        $dolares = MetodoPago::create(['codigo' => 'DOLARES', 'nombre' => 'Dólares en billete', 'afecta_caja' => 1, 'activo' => 1]);
        $admin = $this->usuario('admin');
        $total = (float) $this->vender($admin, 0)->total;

        $venta = $this->vender($admin, 0, [[
            'metodo_pago_id' => $dolares->id, 'monto' => null, 'monto_recibido' => $total + 10,
        ]]);
        $this->assertSame(10.0, (float) $venta->pagos()->value('vuelto'));

        // Y al revés: lo que no pasa por el cajón no da vuelto, se llame como se llame.
        MetodoPago::whereKey($this->metodo('EFECTIVO'))->update(['afecta_caja' => 0]);
        $venta = $this->vender($admin, 0, [[
            'metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null, 'monto_recibido' => $total + 10, 'referencia' => 'OP-1',
        ]]);
        $this->assertNull($venta->pagos()->value('monto_recibido'));
        $this->assertSame(0.0, (float) $venta->pagos()->value('vuelto'));
    }

    /** El mostrador pide el monto recibido con la misma regla. */
    public function test_el_mostrador_pide_lo_recibido_segun_lo_que_entra_al_cajon(): void
    {
        $dolares = MetodoPago::create(['codigo' => 'DOLARES', 'nombre' => 'Dólares en billete', 'afecta_caja' => 1, 'activo' => 1]);
        $cajero = $this->usuario('cajero1');
        $this->turno($cajero);

        $efectivos = MetodoPago::activos()->where('afecta_caja', 1)->orderBy('id')->pluck('id')->all();
        $this->assertContains($dolares->id, $efectivos);

        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
            ->assertSee("efectivos: JSON.parse('".json_encode($efectivos)."')", false);
    }

    // ============================================= C3 · la base y PHP, iguales

    /** Un valor vacío en la configuración cuenta como ausente, en las dos vías. */
    public function test_un_valor_vacio_en_la_configuracion_cuenta_como_ausente(): void
    {
        DB::table('configuracion')->where('clave', 'cliente_generico_nombre')->update(['valor' => '']);
        Config::olvidar();

        $venta = $this->vender($this->usuario('admin'), 0);

        $this->assertSame('Cliente varios', $venta->comprobante->cliente_nombre);
    }

    /**
     * Anular una venta de un turno cerrado cambiaría un arqueo ya firmado. La
     * aplicación lo avisaba, pero la base —y su réplica en PHP— lo dejaba pasar
     * si alguien llamaba al procedimiento directo.
     */
    public function test_la_base_no_anula_una_venta_de_un_turno_cerrado(): void
    {
        $admin = $this->usuario('admin');
        $venta = $this->vender($admin, 0);
        $turno = $venta->sesionCaja->fresh();
        Cajas::cerrar($turno, $admin, $turno->efectivoEsperado(), null, 0, $turno->huella(), conCuentasAbiertas: true);

        try {
            ReglasEnPhp::activa()
                ? ReglasEnPhp::anularVenta($venta->id, $admin->id, 'Por debajo de la aplicación')
                : DB::statement('CALL sp_anular_venta(?, ?, ?)', [$venta->id, $admin->id, 'Por debajo de la aplicación']);
            $this->fail('Se anuló una venta de un turno cerrado.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('turno de caja de esta venta ya cerró', $e->getMessage());
        }

        $this->assertSame('COMPLETADA', $venta->fresh()->estado);

        // Y la aplicación sigue diciéndolo con su propio mensaje.
        $this->assertThrows(fn () => Ventas::anular($venta->fresh(), $admin, 'Desde la pantalla'), RuntimeException::class, 'ya cerró');
    }

    // ============================================= C4 · una sola copia, sin restos

    /** En PHP, la fórmula del arqueo está una sola vez: la del cierre es la de la pantalla. */
    public function test_el_arqueo_en_php_usa_la_misma_cuenta_que_la_pantalla(): void
    {
        $metodo = new ReflectionMethod(ReglasEnPhp::class, 'cerrarCaja');
        $fuente = implode('', array_slice(
            file($metodo->getFileName()),
            $metodo->getStartLine() - 1,
            $metodo->getEndLine() - $metodo->getStartLine() + 1,
        ));

        $this->assertStringContainsString('desgloseDelEfectivo()', $fuente);
        $this->assertStringNotContainsString('afecta_caja', $fuente, 'la fórmula del arqueo volvió a copiarse');
    }

    /** Lo que nadie llamaba y solo confundía: una «guarda» del QR que no guardaba nada y un total sin impuesto. */
    public function test_no_quedan_la_guarda_muerta_del_qr_ni_el_total_trampa_del_pedido(): void
    {
        $this->assertFalse(method_exists(CobrosQr::class, 'consumir'));
        $this->assertFalse((new Pedido)->hasGetMutator('importe'));
    }
}
