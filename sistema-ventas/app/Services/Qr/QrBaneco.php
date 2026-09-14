<?php

namespace App\Services\Qr;

use App\Models\CobroQr;
use App\Services\CobrosQr;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cobro por QR con Banco Económico (BEC QR Connect, «API Market» v1.3.0).
 *
 * El flujo del banco tiene cuatro llamadas, todas con un token que se pide
 * aparte y dura 30 minutos:
 *
 *   POST   api/authentication/authenticate   usuario + contraseña cifrada → token
 *   POST   api/qrsimple/generateQR           → qrId + imagen PNG en base64
 *   GET    api/qrsimple/v2/statusQR/{qrId}   → 0 pendiente, 1 pagado, 9 anulado
 *   DELETE api/qrsimple/cancelQR             → anula un QR que no se pagó
 *
 * Tres decisiones que no están en el manual y conviene no deshacer:
 *
 * 1. EL CIFRADO se hace aquí y no con su `api/authentication/encrypt`, que pide
 *    la llave en la URL. Es AES-256-CBC con la llave tal cual (32 caracteres),
 *    un vector aleatorio de 16 bytes adelante y todo en base64. Se dedujo
 *    descifrando el ejemplo del propio manual, y el banco lo aceptó.
 *
 * 2. EL AVISO DE PAGO no trae firma: cualquiera que conozca la dirección podría
 *    decir «este QR ya se pagó». Por eso el aviso nunca marca nada por sí solo:
 *    solo dispara una consulta al banco con nuestro token, y lo que vale es lo
 *    que el banco conteste. Ver {@see CobrosQr::procesarAviso()}.
 *
 * 3. EL IMPORTE PAGADO se compara con el del cobro aunque el QR se genere con
 *    `modifyAmount = false`: si el banco reportara otro importe, el cobro no se
 *    da por pagado y queda en el log para revisarlo.
 *
 * Probado contra el ambiente de certificación el 14/09/2026. Diferencias con el
 * manual: el campo real es `statusQrCode` (no `statusQRCode`), un token
 * inválido responde HTTP 500 vacío, y los errores de negocio llegan con HTTP
 * 200 y un `responseCode` distinto de cero.
 */
class QrBaneco implements PasarelaQr
{
    public const CODIGO = 'baneco';

    private const PAGADO = 1;

    private const ANULADO = 9;

    /** El pago que devolvió la última consulta, para guardar su referencia. */
    private ?array $ultimoPago = null;

    /**
     * @param  array<string, mixed>  $config  el bloque `qr.pasarelas.baneco`
     */
    public function __construct(private readonly array $config) {}

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function generar(CobroQr $cobro): CobroQr
    {
        $respuesta = $this->llamar('post', 'api/qrsimple/generateQR', array_filter([
            'transactionId' => $this->transaccion($cobro),
            'accountCredit' => $this->cifrar($this->requerido('cuenta')),
            'currency' => $cobro->moneda,
            'amount' => round((float) $cobro->monto, 2),
            'description' => Str::limit((string) $cobro->glosa, 100, ''),
            // El banco solo admite fecha, sin hora. El vencimiento corto lo
            // controla el sistema: al pasar `expira_en` se anula el QR en el banco.
            'dueDate' => ($cobro->expira_en ?? now())->format('Y-m-d'),
            'singleUse' => true,
            'modifyAmount' => false,
            'branchCode' => $this->config['sucursal'] ?? null,
        ], fn ($valor) => $valor !== null && $valor !== ''));

        if (blank($respuesta['qrId'] ?? null) || blank($respuesta['qrImage'] ?? null)) {
            throw new RuntimeException('El banco no devolvió el QR. Cobra por otro medio.');
        }

        $cobro->id_externo = (string) $respuesta['qrId'];
        // Se guarda como data URL: el mostrador sabe por el prefijo que es una
        // imagen para mostrar y no un texto para dibujar.
        $cobro->payload = 'data:image/png;base64,'.$respuesta['qrImage'];
        $cobro->respuesta = json_encode(['qrId' => $respuesta['qrId'], 'responseCode' => $respuesta['responseCode'] ?? null]);
        $cobro->save();

        return $cobro;
    }

    public function consultar(CobroQr $cobro): string
    {
        $this->ultimoPago = null;

        if (! $cobro->id_externo) {
            return $cobro->estado;
        }

        $respuesta = $this->llamar('get', 'api/qrsimple/v2/statusQR/'.rawurlencode($cobro->id_externo));
        $estado = (int) ($respuesta['statusQrCode'] ?? $respuesta['statusQRCode'] ?? 0);

        if ($estado === self::ANULADO) {
            return $cobro->venció() ? CobroQr::EXPIRADO : CobroQr::ANULADO;
        }

        if ($estado === self::PAGADO) {
            $pago = $this->pagoDe($respuesta['payment'] ?? null);

            if (! $this->pagoCoincide($cobro, $pago)) {
                return CobroQr::PENDIENTE;
            }

            $this->ultimoPago = $pago;

            return CobroQr::PAGADO;
        }

        // Pendiente y vencido: se anula en el banco para que nadie lo pague
        // después, cuando ya no hay venta esperándolo.
        if ($cobro->venció()) {
            $this->anular($cobro);

            return CobroQr::EXPIRADO;
        }

        return CobroQr::PENDIENTE;
    }

    public function anular(CobroQr $cobro): void
    {
        if (! $cobro->id_externo) {
            return;
        }

        $this->llamar('delete', 'api/qrsimple/cancelQR', ['qrId' => $cobro->id_externo]);
    }

    public function referenciaDelPago(): ?string
    {
        $pago = $this->ultimoPago;

        if (! $pago) {
            return null;
        }

        return Str::limit(trim(implode(' · ', array_filter([
            $pago['transactionId'] ?? null,
            $pago['senderName'] ?? null,
        ]))), 80, '');
    }

    /**
     * El aviso no trae firma que comprobar. Se acepta si tiene la forma
     * esperada, y la verdad la decide la consulta que se hace a continuación.
     */
    public function verificarAviso(array $datos, array $cabeceras): bool
    {
        return filled($this->idExternoDelAviso($datos));
    }

    public function idExternoDelAviso(array $datos): ?string
    {
        $pago = $datos['payment'] ?? $datos['Payment'] ?? null;
        $id = is_array($pago) ? ($pago['qrId'] ?? null) : null;

        return filled($id) ? (string) $id : null;
    }

    // ================================================================ cifrado

    /** AES-256-CBC, vector aleatorio adelante, base64: lo que espera el banco. */
    public function cifrar(string $texto): string
    {
        $vector = random_bytes(16);
        $cifrado = openssl_encrypt($texto, 'aes-256-cbc', $this->llave(), OPENSSL_RAW_DATA, $vector);

        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar el dato para el banco.');
        }

        return base64_encode($vector.$cifrado);
    }

    public function descifrar(string $base64): ?string
    {
        $crudo = base64_decode($base64, true);

        if ($crudo === false || strlen($crudo) < 32) {
            return null;
        }

        $texto = openssl_decrypt(substr($crudo, 16), 'aes-256-cbc', $this->llave(), OPENSSL_RAW_DATA, substr($crudo, 0, 16));

        return $texto === false ? null : $texto;
    }

    private function llave(): string
    {
        $llave = $this->requerido('llave');

        if (strlen($llave) !== 32) {
            throw new RuntimeException('La llave de cifrado del banco tiene que tener 32 caracteres.');
        }

        return $llave;
    }

    // ================================================================ llamadas

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function llamar(string $metodo, string $ruta, array $cuerpo = [], bool $reintento = false): array
    {
        try {
            $respuesta = $this->peticion()
                ->withToken($this->token())
                ->send(strtoupper($metodo), $this->url($ruta), $metodo === 'get' ? [] : ['json' => $cuerpo]);
        } catch (ConnectionException $e) {
            Log::warning('Banco Económico no respondió.', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            throw new RuntimeException('El banco no respondió. Intenta de nuevo o cobra por otro medio.');
        }

        // Un token vencido o revocado vuelve como 401 o como 500 vacío: se pide
        // uno nuevo y se reintenta una sola vez.
        if (! $reintento && ($respuesta->status() === 401 || ($respuesta->serverError() && trim($respuesta->body()) === ''))) {
            Cache::forget($this->claveToken());

            return $this->llamar($metodo, $ruta, $cuerpo, true);
        }

        return $this->exigirExito($respuesta, $ruta);
    }

    private function token(): string
    {
        $guardado = Cache::get($this->claveToken());

        if (is_string($guardado) && $guardado !== '') {
            return $guardado;
        }

        try {
            $respuesta = $this->peticion()->post($this->url('api/authentication/authenticate'), [
                'userName' => $this->requerido('usuario'),
                'password' => $this->cifrar($this->requerido('password')),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Banco Económico no respondió al autenticar.', ['error' => $e->getMessage()]);

            throw new RuntimeException('El banco no respondió. Intenta de nuevo o cobra por otro medio.');
        }

        $datos = $this->exigirExito($respuesta, 'api/authentication/authenticate');
        $token = (string) ($datos['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('El banco no entregó el acceso para cobrar por QR.');
        }

        // Hasta un minuto antes de que venza, según el propio token (30 min).
        Cache::put($this->claveToken(), $token, now()->addSeconds($this->segundosDeVida($token) - 60));

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function exigirExito(Response $respuesta, string $ruta): array
    {
        $datos = $respuesta->json();

        if ($respuesta->failed() || ! is_array($datos)) {
            Log::error('Banco Económico respondió con error.', ['ruta' => $ruta, 'http' => $respuesta->status()]);

            throw new RuntimeException('El banco no pudo atender el pedido. Intenta de nuevo o cobra por otro medio.');
        }

        $codigo = (int) ($datos['responseCode'] ?? 0);

        if ($codigo !== 0) {
            $mensaje = trim((string) ($datos['message'] ?? ''));
            Log::error('Banco Económico rechazó el pedido.', ['ruta' => $ruta, 'responseCode' => $codigo, 'message' => $mensaje]);

            throw new RuntimeException('El banco rechazó el pedido'.($mensaje !== '' ? ": {$mensaje}" : '.'));
        }

        return $datos;
    }

    private function peticion(): PendingRequest
    {
        return Http::timeout((int) ($this->config['timeout'] ?? 15))
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();
    }

    private function url(string $ruta): string
    {
        return rtrim($this->requerido('url_base'), '/').'/'.ltrim($ruta, '/');
    }

    private function claveToken(): string
    {
        return 'qr.baneco.token.'.md5($this->requerido('url_base').'|'.$this->requerido('usuario'));
    }

    private function segundosDeVida(string $token): int
    {
        $partes = explode('.', $token);
        $datos = isset($partes[1]) ? json_decode((string) base64_decode(strtr($partes[1], '-_', '+/')), true) : null;
        $vence = is_array($datos) ? (int) ($datos['exp'] ?? 0) : 0;

        return max(120, min($vence > 0 ? $vence - time() : 1800, 1800));
    }

    /** Único por cobro y dentro de los 30 caracteres del banco. */
    private function transaccion(CobroQr $cobro): string
    {
        $prefijo = Str::upper(Str::limit((string) ($this->config['prefijo'] ?? 'SV'), 8, ''));

        return Str::limit($prefijo.$cobro->id.'T'.Str::upper(base_convert((string) time(), 10, 36)), 30, '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pagoDe(mixed $pago): ?array
    {
        // El manual lo describe como objeto y su ejemplo lo trae como lista.
        if (is_array($pago) && array_is_list($pago)) {
            $pago = $pago[0] ?? null;
        }

        return is_array($pago) ? $pago : null;
    }

    private function pagoCoincide(CobroQr $cobro, ?array $pago): bool
    {
        if (! $pago) {
            Log::warning('Banco Económico marcó pagado un QR sin detalle del pago.', ['cobro' => $cobro->id]);

            return false;
        }

        $importe = round((float) ($pago['amount'] ?? 0), 2);
        $moneda = (string) ($pago['currency'] ?? $cobro->moneda);

        if (abs($importe - round((float) $cobro->monto, 2)) > 0.001 || $moneda !== $cobro->moneda) {
            Log::error('El pago del QR no coincide con el cobro: no se da por pagado.', [
                'cobro' => $cobro->id, 'esperado' => $cobro->monto, 'pagado' => $importe, 'moneda' => $moneda,
            ]);

            return false;
        }

        return true;
    }

    private function requerido(string $clave): string
    {
        $valor = trim((string) ($this->config[$clave] ?? ''));

        if ($valor === '') {
            throw new RuntimeException("Falta configurar «{$clave}» de Banco Económico para cobrar por QR.");
        }

        return $valor;
    }
}
