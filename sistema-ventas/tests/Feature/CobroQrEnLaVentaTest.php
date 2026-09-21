<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Lo que el servidor exige a un pago por QR, venga lo que venga del mostrador.
 *
 * Antes lo revisaba el controlador, fuera de la transacción y saltándose el
 * importe cuando la línea iba sin monto: un QR de Bs 5 pagaba una venta mayor,
 * una venta «por QR» se aceptaba sin cobro detrás, y un QR pagado podía
 * respaldar un pago en efectivo. Ahora lo decide `Ventas::registrar`, con la
 * fila del cobro bloqueada. Cada prueba confirma también que, si la regla
 * falla, la venta no llega a existir.
 */
class CobroQrEnLaVentaTest extends TestCase
{
    use DatabaseTransactions;

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        $usuario ??= $this->cajero();

        return Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100);
    }

    private function producto(): Producto
    {
        return Producto::where('codigo', 'P-0004')->firstOrFail();
    }

    private function metodo(string $codigo): int
    {
        return MetodoPago::where('codigo', $codigo)->value('id');
    }

    /** Lo que cobra la venta de `$cantidad` unidades, con su impuesto. */
    private function total(float $cantidad): float
    {
        $producto = $this->producto();
        $base = round((float) $producto->precio_venta * $cantidad, 2);
        $tasa = $producto->afecto_impuesto ? (float) Config::get('tasa_impuesto', '0') : 0.0;

        return round($base + round($base * $tasa, 2), 2);
    }

    private function cobroPagado(float $monto, ?Usuario $usuario = null): CobroQr
    {
        $usuario ??= $this->cajero();
        $cobro = CobrosQr::generar($this->turno($usuario), $usuario, $monto);

        return CobrosQr::confirmarAMano($cobro, $usuario);
    }

    /** @param  array<int, array<string, mixed>>  $pagos */
    private function vender(float $cantidad, array $pagos)
    {
        return $this->actingAs($this->cajero())->post(route('pos.store'), [
            'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => $cantidad]],
            'pagos' => $pagos,
        ]);
    }

    private function sinVenta(): void
    {
        $this->assertSame(0, Venta::where('sesion_caja_id', $this->turno()->id)->count());
    }

    // ---------------------------------------------------------------- reglas

    public function test_una_venta_por_qr_sin_cobro_se_rechaza(): void
    {
        $this->turno();
        $this->vender(1, [['metodo_pago_id' => $this->metodo('QR')]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'necesita su cobro'));

        $this->sinVenta();
    }

    /** El caso crítico: QR de Bs 5, carrito que creció, línea «el resto». */
    public function test_un_qr_por_menos_no_paga_una_venta_mayor_aunque_la_linea_vaya_sin_importe(): void
    {
        $cobro = $this->cobroPagado(5.00);
        $this->vender(5, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'el total cambió'));

        $this->sinVenta();
        $this->assertNull($cobro->fresh()->venta_id);
    }

    public function test_un_cobro_qr_no_respalda_un_pago_en_efectivo(): void
    {
        $cobro = $this->cobroPagado($this->total(1));
        $this->vender(1, [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'cobro_qr_id' => $cobro->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'solo puede respaldar un pago por QR'));

        $this->sinVenta();
    }

    public function test_el_mismo_cobro_no_paga_dos_lineas(): void
    {
        $mitad = round($this->total(2) / 2, 2);
        $cobro = $this->cobroPagado($mitad);
        $this->vender(2, [
            ['metodo_pago_id' => $this->metodo('QR'), 'monto' => $mitad, 'cobro_qr_id' => $cobro->id],
            ['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id],
        ])->assertSessionHas('error');

        $this->sinVenta();
    }

    public function test_un_cobro_de_otro_turno_no_se_puede_usar(): void
    {
        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $ajeno = $this->cobroPagado($this->total(1), $admin);
        Cajas::cerrar($this->turno($admin)->fresh(), $admin, 100);
        $this->turno();
        $this->vender(1, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $ajeno->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no es de este turno'));

        $this->sinVenta();
    }

    public function test_un_cobro_sin_pagar_no_se_usa(): void
    {
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), $this->total(1));
        $this->vender(1, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'todavía no está pagado'));

        $this->sinVenta();
    }

    // ---------------------------------------------------------------- caminos buenos

    public function test_la_venta_por_qr_exacta_queda_atada_a_su_cobro(): void
    {
        $cobro = $this->cobroPagado($this->total(2));

        $this->vender(2, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $venta = Venta::where('sesion_caja_id', $this->turno()->id)->firstOrFail();
        $this->assertSame($venta->id, $cobro->fresh()->venta_id);
        $this->assertSame((float) $cobro->monto, (float) $venta->total);
    }

    /** Si la venta falla por otra causa, el cobro pagado sigue libre para reintentar. */
    public function test_si_la_venta_falla_el_cobro_queda_libre(): void
    {
        $cobro = $this->cobroPagado($this->total(1));

        $this->vender(1, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id, 'monto' => $this->total(1) + 1]])
            ->assertSessionHas('error');

        $this->assertTrue($cobro->fresh()->estaLibre());
    }
}
