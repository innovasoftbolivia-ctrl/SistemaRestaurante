<?php

namespace App\Services\Qr;

use App\Models\CobroQr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * La pasarela del banco. PLANTILLA: hay que completarla con la API real.
 *
 * Está escrita a propósito como un esqueleto que ya tiene todo lo que rodea a
 * las llamadas —configuración, tiempos de espera, registro de errores, mapeo
 * de estados— para que al llegar el convenio solo haya que ajustar tres cosas:
 * las rutas, los nombres de los campos y la forma de autenticar.
 *
 * Qué pedirle al banco cuando se abra el trámite:
 *
 *   1. URL base del ambiente de pruebas y del de producción.
 *   2. Credenciales: casi siempre un id de comercio y un secreto, a veces un
 *      certificado. Van al `.env`, NUNCA a este archivo.
 *   3. La ruta que genera un QR con importe, y qué devuelve: identificador de
 *      la transacción y el contenido del QR (texto o imagen en base64).
 *   4. La ruta que consulta el estado de una transacción, y la lista exacta de
 *      estados que puede devolver.
 *   5. Si ofrecen webhook: la dirección se les da (ver `qr.webhook` en las
 *      rutas) y hay que preguntar CÓMO SE FIRMA el aviso. Sin firma, esa
 *      dirección es pública y cualquiera podría dar por pagado un cobro.
 *   6. El plazo de vencimiento del QR que admiten.
 *
 * Mientras tanto el sistema usa {@see QrSimulado}, y el cajero confirma a mano.
 */
class QrBanco implements PasarelaQr
{
    /**
     * @param  array<string, mixed>  $config  el bloque `qr.pasarelas.<codigo>`
     */
    public function __construct(
        private readonly string $codigo,
        private readonly array $config,
    ) {}

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function generar(CobroQr $cobro): CobroQr
    {
        $respuesta = $this->llamar('post', $this->config['ruta_generar'] ?? '/qr', [
            // TODO(banco): los nombres reales de los campos salen de su manual.
            'monto' => number_format((float) $cobro->monto, 2, '.', ''),
            'moneda' => $cobro->moneda,
            'glosa' => $cobro->glosa,
            'expiracion' => $cobro->expira_en?->toIso8601String(),
            'referencia' => 'COBRO-'.$cobro->id,
        ]);

        // TODO(banco): ajustar de dónde salen el identificador y el contenido.
        $cobro->id_externo = $respuesta['id'] ?? $respuesta['transactionId'] ?? null;
        $cobro->payload = $respuesta['qr'] ?? $respuesta['qrContent'] ?? null;
        $cobro->respuesta = json_encode($respuesta);

        if (! $cobro->id_externo || ! $cobro->payload) {
            throw new RuntimeException('El banco no devolvió el QR. Cobra por otro medio y avisa a soporte.');
        }

        $cobro->save();

        return $cobro;
    }

    public function consultar(CobroQr $cobro): string
    {
        if (! $cobro->id_externo) {
            return $cobro->estado;
        }

        $ruta = str_replace('{id}', $cobro->id_externo, $this->config['ruta_consultar'] ?? '/qr/{id}');
        $respuesta = $this->llamar('get', $ruta);

        // TODO(banco): completar con los estados exactos de su documentación.
        $suyo = mb_strtoupper((string) ($respuesta['estado'] ?? $respuesta['status'] ?? ''));

        return match ($suyo) {
            'PAGADO', 'PAID', 'COMPLETED', 'SUCCESS' => CobroQr::PAGADO,
            'EXPIRADO', 'EXPIRED', 'TIMEOUT' => CobroQr::EXPIRADO,
            'ANULADO', 'CANCELLED', 'CANCELED' => CobroQr::ANULADO,
            default => CobroQr::PENDIENTE,
        };
    }

    public function anular(CobroQr $cobro): void
    {
        // TODO(banco): la ruta de anulación sale de su manual.
    }

    public function referenciaDelPago(): ?string
    {
        return null;
    }

    /**
     * Sin secreto configurado se rechaza TODO aviso.
     *
     * Fallar cerrado y no abierto: si alguien despliega esto sin terminar de
     * configurar, el peor caso es que los cobros haya que confirmarlos a mano
     * —molesto— y no que un desconocido pueda darlos por pagados.
     */
    public function verificarAviso(array $datos, array $cabeceras): bool
    {
        $secreto = $this->config['secreto_webhook'] ?? null;

        if (! $secreto) {
            Log::warning('Aviso de QR recibido sin secreto configurado: se rechaza.', [
                'pasarela' => $this->codigo,
            ]);

            return false;
        }

        // TODO(banco): el nombre del encabezado y el algoritmo son suyos.
        $firma = $cabeceras['x-signature'] ?? $cabeceras['X-Signature'] ?? '';
        $esperada = hash_hmac('sha256', json_encode($datos), $secreto);

        return hash_equals($esperada, (string) $firma);
    }

    public function idExternoDelAviso(array $datos): ?string
    {
        // TODO(banco): el campo real sale de su manual.
        return $datos['id'] ?? $datos['transactionId'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function llamar(string $metodo, string $ruta, array $cuerpo = []): array
    {
        $base = rtrim((string) ($this->config['url_base'] ?? ''), '/');

        if ($base === '') {
            throw new RuntimeException('La pasarela de QR no tiene URL configurada.');
        }

        $peticion = Http::timeout((int) ($this->config['timeout'] ?? 15))
            ->acceptJson()
            ->withHeaders($this->config['cabeceras'] ?? [])
            ->withToken((string) ($this->config['token'] ?? ''));

        $respuesta = $metodo === 'get'
            ? $peticion->get($base.$ruta)
            : $peticion->post($base.$ruta, $cuerpo);

        if ($respuesta->failed()) {
            Log::error('La pasarela de QR respondió con error.', [
                'pasarela' => $this->codigo,
                'ruta' => $ruta,
                'estado' => $respuesta->status(),
            ]);

            throw new RuntimeException('El banco no respondió. Cobra por otro medio.');
        }

        return $respuesta->json() ?? [];
    }
}
