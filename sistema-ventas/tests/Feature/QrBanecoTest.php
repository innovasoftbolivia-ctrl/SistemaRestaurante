<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Qr\QrBaneco;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * La pasarela de Banco Económico, con el banco simulado por Http::fake.
 *
 * Las respuestas imitan las que dio el ambiente de certificación el 14/09/2026
 * —incluido `statusQrCode` con «r» minúscula—, no solo las del manual.
 * Credenciales y llave son de prueba: las reales nunca van al repositorio.
 */
class QrBanecoTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'https://banco.test/ApiGateway';

    private const LLAVE = 'ABCDEF0123456789ABCDEF0123456789';

    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjQxMDI0NDQ4MDAsImlhdCI6MTcwMDAwMDAwMH0.firma';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'qr.pasarela' => 'baneco',
            'qr.pasarelas.baneco' => [
                'url_base' => self::BASE,
                'usuario' => 'usuario-prueba',
                'password' => 'clave-prueba',
                'llave' => self::LLAVE,
                'cuenta' => '1234567890',
                'sucursal' => null,
                'prefijo' => 'SV',
                'timeout' => 5,
            ],
        ]);
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        return Cajas::sesionDe($this->cajero()) ?? Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100);
    }

    private function pasarela(): QrBaneco
    {
        return CobrosQr::pasarela();
    }

    /**
     * @param  array<string, mixed>  $estado  respuesta de statusQR
     */
    private function banco(array $estado = ['statusQrCode' => 0, 'payment' => []]): void
    {
        Http::fake([
            self::BASE.'/api/authentication/authenticate' => Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/generateQR' => Http::response(['qrId' => '26091401016609000085', 'qrImage' => 'iVBORw0KGgoAAAANSUhEUg', 'responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/v2/statusQR/*' => Http::response($estado + ['responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/cancelQR' => Http::response(['responseCode' => 0, 'message' => '']),
        ]);
    }

    private function pago(float $importe, string $moneda = 'BOB'): array
    {
        return ['statusQrCode' => 1, 'payment' => [[
            'qrId' => '26091401016609000085', 'transactionId' => '3161056', 'paymentDate' => '2026-09-14T00:00:00',
            'paymentTime' => '15:00:27', 'currency' => $moneda, 'amount' => $importe, 'senderBankCode' => '1016',
            'senderName' => 'PEDRO PEREZ', 'senderDocumentId' => '0', 'senderAccount' => '******1913',
        ]]];
    }

    // ================================================================ cifrado

    public function test_cifra_como_el_banco_aes_256_cbc_con_vector_adelante(): void
    {
        $cifrado = $this->pasarela()->cifrar('1234');
        $crudo = base64_decode($cifrado);

        $this->assertSame(32, strlen($crudo), '16 del vector + 16 del bloque');
        $this->assertSame('1234', openssl_decrypt(substr($crudo, 16), 'aes-256-cbc', self::LLAVE, OPENSSL_RAW_DATA, substr($crudo, 0, 16)));
        $this->assertNotSame($cifrado, $this->pasarela()->cifrar('1234'), 'el vector es aleatorio');
        $this->assertSame('1234', $this->pasarela()->descifrar($cifrado));
    }

    // ================================================================ generar

    public function test_genera_el_qr_con_los_datos_que_pide_el_banco(): void
    {
        $this->banco();

        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 25.50, 'Venta de prueba');

        $this->assertSame('26091401016609000085', $cobro->id_externo);
        $this->assertSame('data:image/png;base64,iVBORw0KGgoAAAANSUhEUg', $cobro->payload);

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/api/authentication/authenticate')) {
                return false;
            }

            return $r['userName'] === 'usuario-prueba'
                && $this->pasarela()->descifrar($r['password']) === 'clave-prueba';
        });

        Http::assertSent(function (Request $r) use ($cobro) {
            if (! str_ends_with($r->url(), '/api/qrsimple/generateQR')) {
                return false;
            }

            return $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && str_starts_with($r['transactionId'], 'SV'.$cobro->id.'T')
                && strlen($r['transactionId']) <= 30
                && $this->pasarela()->descifrar($r['accountCredit']) === '1234567890'
                && $r['currency'] === 'BOB'
                && $r['amount'] === 25.5
                && $r['description'] === 'Venta de prueba'
                && $r['dueDate'] === $cobro->expira_en->format('Y-m-d')
                && $r['singleUse'] === true
                && $r['modifyAmount'] === false
                && ! array_key_exists('branchCode', $r->data());
        });
    }

    public function test_el_token_se_reusa_mientras_no_vence(): void
    {
        Http::fake([
            self::BASE.'/api/authentication/authenticate' => Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/generateQR' => Http::sequence()
                ->push(['qrId' => 'QR-A', 'qrImage' => 'iVBOR', 'responseCode' => 0, 'message' => ''])
                ->push(['qrId' => 'QR-B', 'qrImage' => 'iVBOR', 'responseCode' => 0, 'message' => '']),
        ]);

        CobrosQr::generar($this->turno(), $this->cajero(), 10);
        CobrosQr::generar($this->turno(), $this->cajero(), 11);

        $autenticaciones = collect(Http::recorded())->filter(fn ($par) => str_ends_with($par[0]->url(), '/authenticate'));
        $this->assertCount(1, $autenticaciones);
    }

    public function test_con_el_token_vencido_pide_otro_y_reintenta_una_vez(): void
    {
        Http::fake([
            self::BASE.'/api/authentication/authenticate' => Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/generateQR' => Http::sequence()
                ->push('', 500)
                ->push(['qrId' => 'QR-2', 'qrImage' => 'iVBOR', 'responseCode' => 0, 'message' => '']),
        ]);

        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 10);

        $this->assertSame('QR-2', $cobro->id_externo);
        $autenticaciones = collect(Http::recorded())->filter(fn ($par) => str_ends_with($par[0]->url(), '/authenticate'));
        $this->assertCount(2, $autenticaciones);
    }

    public function test_un_rechazo_del_banco_se_muestra_con_su_mensaje(): void
    {
        Http::fake([
            self::BASE.'/api/authentication/authenticate' => Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => '']),
            self::BASE.'/api/qrsimple/generateQR' => Http::response(['qrId' => '', 'qrImage' => '', 'responseCode' => 404, 'message' => 'El numero de cuenta 1234567890 para abonar el pago del QR no existe']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El banco rechazó el pedido: El numero de cuenta');

        CobrosQr::generar($this->turno(), $this->cajero(), 10);
    }

    // ================================================================ consultar

    public function test_pendiente_mientras_el_banco_no_registra_el_pago(): void
    {
        $this->banco();
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->assertSame(CobroQr::PENDIENTE, CobrosQr::refrescar($cobro)->estado);
    }

    public function test_pagado_cuando_el_banco_lo_registra_por_el_mismo_importe(): void
    {
        $this->banco($this->pago(18.00));
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $cobro = CobrosQr::refrescar($cobro);

        $this->assertSame(CobroQr::PAGADO, $cobro->estado);
        $this->assertSame('PASARELA', $cobro->confirmado_por);
        $this->assertStringContainsString('3161056', (string) $cobro->referencia_bancaria);
    }

    public function test_un_pago_por_otro_importe_no_se_da_por_pagado(): void
    {
        $this->banco($this->pago(1.00));
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->assertSame(CobroQr::PENDIENTE, CobrosQr::refrescar($cobro)->estado);
    }

    public function test_anulado_en_el_banco(): void
    {
        $this->banco(['statusQrCode' => 9, 'payment' => []]);
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->assertSame(CobroQr::ANULADO, CobrosQr::refrescar($cobro)->estado);
    }

    /** Un QR vencido se anula en el banco: nadie lo puede pagar después. */
    public function test_al_vencer_se_anula_en_el_banco(): void
    {
        $this->banco();
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);
        $cobro->forceFill(['expira_en' => now()->subMinute()])->save();

        $this->assertSame(CobroQr::EXPIRADO, CobrosQr::refrescar($cobro)->estado);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/api/qrsimple/cancelQR')
            && $r['qrId'] === '26091401016609000085');
    }

    public function test_cancelar_el_cobro_lo_anula_en_el_banco(): void
    {
        $this->banco();
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->assertSame(CobroQr::ANULADO, CobrosQr::anular($cobro, $this->cajero())->estado);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/cancelQR'));
    }

    // ================================================================ a mano

    /** Con el banco conectado, la palabra del cajero no alcanza si el banco dice que no. */
    public function test_no_se_confirma_a_mano_si_el_banco_dice_que_sigue_pendiente(): void
    {
        $this->banco();
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        try {
            CobrosQr::confirmarAMano($cobro, $this->cajero());
            $this->fail('se confirmó a mano un QR que el banco no registra');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('todavía no registra', $e->getMessage());
        }

        $this->assertFalse($cobro->fresh()->estaPagado());
    }

    public function test_si_el_banco_no_responde_se_puede_confirmar_a_mano(): void
    {
        $caido = false;
        Http::fake(function (Request $r) use (&$caido) {
            if ($caido) {
                return Http::failedConnection();
            }

            return str_ends_with($r->url(), '/authenticate')
                ? Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => ''])
                : Http::response(['qrId' => 'QR-9', 'qrImage' => 'iVBOR', 'responseCode' => 0, 'message' => '']);
        });
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);
        $caido = true;

        $cobro = CobrosQr::confirmarAMano($cobro, $this->cajero(), 'OP-55');

        $this->assertTrue($cobro->estaPagado());
        $this->assertSame('MANUAL', $cobro->confirmado_por);
    }

    // ================================================================ aviso

    /** El aviso no trae firma: se le pregunta al banco y vale lo que conteste. */
    public function test_un_aviso_falso_no_da_por_pagado_un_cobro(): void
    {
        $this->banco();
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->postJson(route('qr.aviso.baneco'), ['payment' => ['qrId' => $cobro->id_externo, 'amount' => 18.00, 'currency' => 'BOB']])
            ->assertOk()
            ->assertJson(['responseCode' => 0, 'message' => '']);

        $this->assertFalse($cobro->fresh()->estaPagado());
    }

    public function test_un_aviso_verdadero_marca_pagado_tras_consultar_al_banco(): void
    {
        $this->banco($this->pago(18.00));
        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 18.00);

        $this->postJson(route('qr.aviso.baneco'), ['payment' => ['qrId' => $cobro->id_externo]])
            ->assertOk()
            ->assertJson(['responseCode' => 0, 'estado' => CobroQr::PAGADO]);

        $this->assertSame('PASARELA', $cobro->fresh()->confirmado_por);
    }

    // ================================================================ cierre de caja

    /**
     * Al cerrar, un QR que el sistema todavía veía pendiente se consulta al
     * banco antes de cancelarlo: si el cliente pagó segundos antes, queda
     * PAGADO (y a la vista como «pagado sin venta»), no vencido con el dinero
     * en el banco.
     */
    public function test_cerrar_la_caja_pregunta_al_banco_antes_de_cancelar_un_qr(): void
    {
        $this->banco($this->pago(18.00));
        $turno = $this->turno();
        $cobro = CobrosQr::generar($turno, $this->cajero(), 18.00);
        $this->assertSame(CobroQr::PENDIENTE, $cobro->estado);

        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $turno = $turno->fresh();
        Cajas::cerrar($turno, $admin, $turno->efectivoEsperado(), null, 0, $turno->huella());

        $this->assertSame(CobroQr::PAGADO, $cobro->fresh()->estado);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/qrsimple/cancelQR'));
    }

    /** Y uno que de verdad sigue pendiente sí se cancela en el banco. */
    public function test_cerrar_la_caja_cancela_el_qr_que_sigue_pendiente(): void
    {
        $this->banco();
        $turno = $this->turno();
        $cobro = CobrosQr::generar($turno, $this->cajero(), 18.00);

        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $turno = $turno->fresh();
        Cajas::cerrar($turno, $admin, $turno->efectivoEsperado(), null, 0, $turno->huella());

        $this->assertSame(CobroQr::EXPIRADO, $cobro->fresh()->estado);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/qrsimple/cancelQR'));
    }
}
