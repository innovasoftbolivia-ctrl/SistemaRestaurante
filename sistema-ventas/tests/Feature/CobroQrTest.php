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
use App\Services\Qr\QrBanco;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El cobro por código QR con el importe ya puesto.
 *
 * La regla que ordena todo: el QR se genera contra el CARRITO, y la venta se
 * registra recién cuando el pago está confirmado. Si fuera al revés, un cliente
 * que se arrepiente dejaría una venta cobrada y con comprobante
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

    // ------------------------------------------------ atarlo a una sola venta

    /**
     * Un cobro paga UNA venta. Si no, el mismo QR compraría dos veces. La
     * guarda es la de `Ventas::registrar()`, con la fila del cobro bloqueada
     * dentro de la transacción de la venta: es el único camino que ata un
     * cobro a su venta (el viejo `CobrosQr::consumir` no lo llamaba nadie).
     */
    public function test_un_cobro_pagado_solo_se_usa_una_vez(): void
    {
        $sesion = $this->turno();
        $total = $this->totalDe($this->producto(), 1);
        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($sesion, $this->cajero(), $total), $this->cajero());

        $primera = $this->ventaPorQr($sesion, $cobro->id);
        $this->assertSame($primera->id, $cobro->fresh()->venta_id);

        $this->expectExceptionMessage('ya se usó en otra venta');
        $this->ventaPorQr($sesion, $cobro->id);
    }

    public function test_un_cobro_sin_pagar_no_paga_una_venta(): void
    {
        $sesion = $this->turno();
        $cobro = CobrosQr::generar($sesion, $this->cajero(), $this->totalDe($this->producto(), 1));

        $this->expectExceptionMessage('todavía no está pagado');
        $this->ventaPorQr($sesion, $cobro->id);
    }

    private function ventaPorQr(SesionCaja $sesion, int $cobroId): Venta
    {
        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $this->cajero(),
            lineas: [['producto_id' => $this->producto()->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => $this->metodoQr()->id, 'monto' => null, 'cobro_qr_id' => $cobroId]],
        );
    }

    // ------------------------------------------- la venta con pago por QR

    public function test_una_venta_con_pago_por_qr_queda_ligada_al_cobro(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        // El total con impuesto: el QR tiene que ser por lo que la venta cobra.
        $total = $this->totalDe($producto, 2);

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
        $ventasAntes = Venta::count();

        $cobro = CobrosQr::generar($sesion, $this->cajero(), 50.00);

        $this->actingAs($this->cajero())->post(route('pos.store'), [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => $this->metodoQr()->id, 'cobro_qr_id' => $cobro->id]],
        ])->assertSessionHas('error');

        // Ni venta ni comprobante emitido.
        $this->assertSame($ventasAntes, Venta::count());
    }

    /** Un QR de Bs 5 no puede pagar una venta de Bs 50. */
    public function test_el_importe_del_qr_debe_coincidir_con_el_de_la_linea(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto();
        $ventasAntes = Venta::count();

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

        $this->assertSame($ventasAntes, Venta::count());
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
                ['metodo_pago_id' => $this->metodoQr()->id, 'monto' => 3.00, 'cobro_qr_id' => $cobro->id],
                ['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->first()->id, 'monto' => null],
            ],
        );

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

    /**
     * La firma se comprueba sobre el cuerpo tal como lo mandó el banco: el
     * JSON vuelto a armar no tiene los mismos bytes y una firma buena fallaba.
     */
    public function test_la_firma_del_aviso_se_calcula_sobre_el_cuerpo_tal_como_llego(): void
    {
        config([
            'qr.pasarela' => 'banco',
            'qr.pasarelas.banco.secreto_webhook' => 'secreto-de-prueba',
        ]);
        $cuerpo = '{"id": "no-existe-123",  "url": "https://banco.bo/pago"}';
        $firma = hash_hmac('sha256', $cuerpo, 'secreto-de-prueba');

        $pasarela = new QrBanco('banco', ['secreto_webhook' => 'secreto-de-prueba']);
        $datos = json_decode($cuerpo, true);
        $this->assertTrue($pasarela->verificarAviso($datos, ['x-signature' => $firma], $cuerpo));
        $this->assertFalse($pasarela->verificarAviso($datos, ['x-signature' => hash_hmac('sha256', json_encode($datos), 'secreto-de-prueba')], $cuerpo.' '));

        $servidor = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', route('qr.aviso'), [], [], [], $servidor + ['HTTP_X_SIGNATURE' => $firma], $cuerpo)
            ->assertOk()->assertJson(['recibido' => true]);
        $this->call('POST', route('qr.aviso'), [], [], [], $servidor + ['HTTP_X_SIGNATURE' => str_repeat('0', 64)], $cuerpo)
            ->assertStatus(403);
    }

    // ------------------------------------------------------------ auxiliares

    private function turnoActual(): SesionCaja
    {
        return Cajas::sesionDe($this->cajero());
    }

    private function totalDe(Producto $producto, float $cantidad): float
    {
        $base = round((float) $producto->precio_venta * $cantidad, 2);
        $tasa = $producto->afecto_impuesto ? (float) Config::get('tasa_impuesto', '0') : 0.0;

        return round($base + round($base * $tasa, 2), 2);
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

    // ================================================================ vencidos

    /**
     * Un QR vencido seguía cobrable en el banco durante horas: el cliente
     * pagaba tarde, el dinero entraba y no había venta esperándolo. Ahora una
     * tarea los cancela.
     */
    public function test_los_cobros_qr_vencidos_se_cancelan(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 40.00);
        CobroQr::whereKey($cobro->id)->update(['expira_en' => now()->subHour()]);

        $this->artisan('qr:vencer')->assertSuccessful();

        // Queda vencido aquí y cancelado en el banco: la tarea consulta la
        // pasarela y, si sigue sin pagarse, lo cierra.
        $this->assertSame(CobroQr::EXPIRADO, $cobro->fresh()->estado);
        $this->assertFalse($cobro->fresh()->estaPendiente());

        // Correrla de nuevo no rompe nada.
        $this->artisan('qr:vencer')->assertSuccessful();
        $this->assertSame(CobroQr::EXPIRADO, $cobro->fresh()->estado);
    }

    /** Uno vigente no se toca. */
    public function test_un_cobro_qr_vigente_no_se_cancela(): void
    {
        $this->turno();
        $cobro = CobrosQr::generar($this->turnoActual(), $this->cajero(), 25.00);

        $this->artisan('qr:vencer')->assertSuccessful();

        $this->assertSame(CobroQr::PENDIENTE, $cobro->fresh()->estado);
    }
}
