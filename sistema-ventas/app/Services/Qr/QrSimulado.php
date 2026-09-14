<?php

namespace App\Services\Qr;

use App\Models\CobroQr;
use Illuminate\Support\Str;

/**
 * Pasarela de mentira: genera un QR real, pero no hay banco detrás.
 *
 * Sirve para dos cosas mientras no haya convenio firmado:
 *
 *   - desarrollar y probar el flujo completo sin depender de nadie;
 *   - demostrar el sistema a un cliente, que escanea con el celular y ve que
 *     el QR existe y lleva el importe correcto.
 *
 * El pago NO ocurre solo: alguien tiene que marcarlo desde la pantalla. Eso es
 * a propósito. Un simulador que se pagara solo daría la falsa impresión de que
 * el cobro funciona, y el día del convenio nadie se acordaría de que ahí no
 * había banco.
 *
 * El payload imita la forma de un QR de cobro —un texto con el comercio, el
 * importe, la moneda y un identificador— para que lo que se dibuja hoy tenga
 * el mismo tamaño y densidad que lo que dibujará el banco. Un QR de prueba
 * mucho más corto se vería nítido y luego el real saldría ilegible.
 */
class QrSimulado implements PasarelaQr
{
    public function codigo(): string
    {
        return 'simulado';
    }

    public function generar(CobroQr $cobro): CobroQr
    {
        $cobro->id_externo = 'SIM-'.Str::upper(Str::random(18));
        $cobro->payload = sprintf(
            'SIMQR|v1|%s|%s|%s|%s|%s',
            config('app.name'),
            number_format((float) $cobro->monto, 2, '.', ''),
            $cobro->moneda,
            $cobro->id_externo,
            $cobro->expira_en?->timestamp ?? '',
        );
        $cobro->respuesta = json_encode([
            'simulado' => true,
            'aviso' => 'Sin banco detrás: hay que confirmar el pago a mano.',
        ]);
        $cobro->save();

        return $cobro;
    }

    /**
     * Nunca cambia el estado por su cuenta: el simulador no sabe de pagos.
     *
     * Lo único que hace es respetar el vencimiento, que sí es una regla del
     * flujo y conviene tener probada antes de que llegue el banco.
     */
    public function consultar(CobroQr $cobro): string
    {
        if ($cobro->estado === CobroQr::PENDIENTE && $cobro->venció()) {
            return CobroQr::EXPIRADO;
        }

        return $cobro->estado;
    }

    /** No hay banco donde anular nada. */
    public function anular(CobroQr $cobro): void {}

    public function referenciaDelPago(): ?string
    {
        return null;
    }

    /** Sin banco no hay avisos, así que ninguno es de fiar. */
    public function verificarAviso(array $datos, array $cabeceras): bool
    {
        return false;
    }

    public function idExternoDelAviso(array $datos): ?string
    {
        return null;
    }
}
