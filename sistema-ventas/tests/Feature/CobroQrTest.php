<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El cobro por código QR con el importe ya puesto.
 *
 * La regla que ordena todo: el QR se genera contra el CARRITO, y la venta se
 * registra recién cuando el pago está confirmado. Si fuera al revés, un cliente
 * que se arrepiente dejaría una venta con stock descontado y comprobante
 * emitido por mercadería que nadie se llevó.
 */
class CobroQrTest extends TestCase
{
    use DatabaseTransactions;

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->cajero(), 100);
    }

    private function metodoQr(): MetodoPago
    {
        return MetodoPago::where('codigo', 'QR')->firstOrFail();
    }

    private function producto(): Producto
    {
        return Producto::activos()->firstOrFail();
    }

    // ------------------------------------------------------------- generar

    public function test_se_genera_un_cobro_pendiente_con_su_codigo(): void
    {
        $sesion = $this->turno();

        $respuesta = $this->actingAs($this->cajero())
            ->postJson(route('qr.crear'), ['monto' => 25.50]);

        $respuesta->assertOk();
        $respuesta->assertJson(['estado' => CobroQr::PENDIENTE, 'monto' => 25.5, 'pagado' => false]);

        $cobro = CobroQr::latest('id')->first();
        $this->assertSame($sesion->id, $cobro->sesion_caja_id);
        $this->assertNotNull($cobro->payload, 'el QR tiene que traer algo que dibujar');
        $this->assertNotNull($cobro->expira_en, 'un QR sin vencimiento se queda vivo para siempre');
        $this->assertNull($cobro->venta_id, 'la venta todavía no existe');
    }

    /** Sin turno abierto no hay dónde imputar el cobro. */
    public function test_no_se_genera_un_cobro_sin_caja_abierta(): void
    {
        $this->actingAs($this->cajero())
            ->postJson(route('qr.crear'), ['monto' => 10])
            ->assertStatus(422);

        $this->assertSame(0, CobroQr::count());
    }

    public function test_no_se_genera_un_cobro_por_cero(): void
    {
        $this->turno();

        $this->actingAs($this->cajero())
            ->postJson(route('qr.crear'), ['monto' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monto');
    }

    // ------------------------------------------------------------ confirmar

    public function test_el_cajero_puede_confirmar_el_pago_a_mano(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 30.00);

        $this->actingAs($this->cajero())
            ->postJson(route('qr.confirmar', $cobro), ['referencia' => 'OP-778899'])
            ->assertOk()
            ->assertJson(['pagado' => true]);

        $cobro->refresh();
        $this->assertTrue($cobro->estaPagado());
        $this->assertSame('MANUAL', $cobro->confirmado_por);
        $this->assertSame($this->cajero()->id, $cobro->confirmado_por_id);
        $this->assertSame('OP-778899', $cobro->referencia_bancaria);
        $this->assertNotNull($cobro->pagado_en);
    }

    /** Confirmar a mano es el punto por donde se colaría un cobro que no entró. */
    public function test_la_confirmacion_a_mano_queda_en_la_bitacora(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 12.00);

        $this->actingAs($this->cajero())->postJson(route('qr.confirmar', $cobro));

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'QR_PAGADO',
            'entidad' => 'cobros_qr',
            'entidad_id' => $cobro->id,
        ]);
    }

    /** Un cobro es de quien lo generó: nadie más lo toca. */
    public function test_un_cobro_ajeno_no_se_puede_confirmar(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 20.00);

        $this->actingAs($this->admin())
            ->postJson(route('qr.confirmar', $cobro))
            ->assertForbidden();

        $this->assertFalse($cobro->fresh()->estaPagado());
    }

    public function test_un_cobro_cancelado_ya_no_se_confirma(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 15.00);

        $this->actingAs($this->cajero())->postJson(route('qr.anular', $cobro))->assertOk();

        $this->actingAs($this->cajero())
            ->postJson(route('qr.confirmar', $cobro))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------- consumir

    /** Un cobro paga UNA venta. Si no, el mismo QR compraría dos veces. */
    public function test_un_cobro_pagado_solo_se_usa_una_vez(): void
    {
        $sesion = $this->turno();
        $cobro = CobrosQr::generar($sesion, $this->cajero(), 10.00);
        CobrosQr::confirmarAMano($cobro, $this->cajero());

        $primera = $this->ventaDe($sesion);
        CobrosQr::consumir($cobro->id, $primera);

        $this->assertSame($primera->id, $cobro->fresh()->venta_id);

        $segunda = $this->ventaDe($sesion);

        $this->expectExceptionMessage('ya se usó en otra venta');
        CobrosQr::consumir($cobro->id, $segunda);
    }

    public function test_un_cobro_sin_pagar_no_se_puede_consumir(): void
    {
        $sesion = $this->turno();
        $cobro = CobrosQr::generar($sesion, $this->cajero(), 10.00);

        $this->expectExceptionMessage('todavía no está pagado');
        CobrosQr::consumir($cobro->id, $this->ventaDe($sesion));
    }

    // ------------------------------------------- la venta con pago por QR

    public function test_una_venta_con_pago_por_qr_queda_ligada_al_cobro(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $total = round((float) $producto->precio_venta * 2, 2);

        $cobro = CobrosQr::generar($sesion, $this->cajero(), $total);
        CobrosQr::confirmarAMano($cobro, $this->cajero());

        $this->actingAs($this->cajero())->post(route('pos.store'), [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [[
                'metodo_pago_id' => $this->metodoQr()->id,
                'cobro_qr_id' => $cobro->id,
            ]],
        ])->assertRedirect();

        $cobro->refresh();
        $this->assertNotNull($cobro->venta_id, 'el cobro tiene que quedar atado a su venta');
    }

    /** El caso que motiva todo el diseño: si no pagó, no hay venta. */
    public function test_no_se_registra_la_venta_si_el_qr_no_esta_pagado(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $stockAntes = (float) $producto->stock_actual;

        $cobro = CobrosQr::generar($sesion, $this->cajero(), 50.00);

        $this->actingAs($this->cajero())->post(route('pos.store'), [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => $this->metodoQr()->id, 'cobro_qr_id' => $cobro->id]],
        ])->assertSessionHas('error');

        // Ni venta, ni stock descontado, ni comprobante emitido.
        $this->assertSame($stockAntes, (float) $producto->fresh()->stock_actual);
    }

    /** Un QR de Bs 5 no puede pagar una venta de Bs 50. */
    public function test_el_importe_del_qr_debe_coincidir_con_el_de_la_linea(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $stockAntes = (float) $producto->stock_actual;

        $cobro = CobrosQr::generar($sesion, $this->cajero(), 5.00);
        CobrosQr::confirmarAMano($cobro, $this->cajero());

        $this->actingAs($this->cajero())->post(route('pos.store'), [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [[
                'metodo_pago_id' => $this->metodoQr()->id,
                'monto' => 50.00,
                'cobro_qr_id' => $cobro->id,
            ]],
        ])->assertSessionHas('error');

        $this->assertSame($stockAntes, (float) $producto->fresh()->stock_actual);
    }

    /** Lo que pidió el negocio: una parte por QR y otra en efectivo. */
    public function test_se_puede_pagar_una_parte_por_qr_y_otra_en_efectivo(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();

        $cobro = CobrosQr::generar($sesion, $this->cajero(), 3.00);
        CobrosQr::confirmarAMano($cobro, $this->cajero());

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 2]],
            pagos: [
                ['metodo_pago_id' => $this->metodoQr()->id, 'monto' => 3.00],
                ['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->first()->id, 'monto' => null],
            ],
        );

        CobrosQr::consumir($cobro->id, $venta);

        $this->assertCount(2, $venta->pagos()->get());
        $this->assertSame($venta->id, $cobro->fresh()->venta_id);

        // El QR no entra al cajón: el dinero cae en la cuenta del banco.
        $this->assertSame(0, (int) $this->metodoQr()->afecta_caja);
    }

    // --------------------------------------------------------------- webhook

    /**
     * El aviso del banco vive fuera de la sesión y fuera de CSRF, así que su
     * única defensa es la firma. Sin pasarela real configurada NADA se acepta:
     * falla cerrado, no abierto.
     */
    public function test_el_aviso_de_pago_sin_firma_se_rechaza(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 40.00);

        $this->postJson(route('qr.aviso'), ['id' => $cobro->id_externo])
            ->assertStatus(403);

        $this->assertFalse($cobro->fresh()->estaPagado());
    }

    // ------------------------------------------------------------ auxiliares

    private function turnoActual(): SesionCaja
    {
        return Cajas::sesionDe($this->cajero());
    }

    private function ventaDe(SesionCaja $sesion)
    {
        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->first()->id, 'monto' => null]],
        );
    }
}
