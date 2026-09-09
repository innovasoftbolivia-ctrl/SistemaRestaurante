<?php

namespace App\Services;

use App\Models\CobroQr;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Qr\PasarelaQr;
use App\Services\Qr\QrBanco;
use App\Services\Qr\QrSimulado;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El cobro por QR, de punta a punta.
 *
 * Coordina las tres piezas: la pasarela del banco (que sabe hablar con él),
 * la tabla `cobros_qr` (que recuerda en qué quedó cada cobro) y las reglas de
 * cuándo un cobro puede usarse para pagar una venta.
 *
 * La regla que ordena todo: un cobro solo sirve UNA vez y solo si está pagado.
 * De eso se encarga {@see self::consumir()}, que además bloquea la fila: dos
 * pestañas del mostrador cobrando a la vez no pueden gastar el mismo QR dos
 * veces.
 */
class CobrosQr
{
    /** Cuánto vale un QR antes de vencerse, si no se configura otra cosa. */
    private const MINUTOS_POR_DEFECTO = 10;

    public static function pasarela(): PasarelaQr
    {
        $codigo = (string) config('qr.pasarela', 'simulado');

        if ($codigo === 'simulado') {
            return new QrSimulado;
        }

        $config = config("qr.pasarelas.{$codigo}");

        if (! is_array($config)) {
            throw new RuntimeException("No hay configuración para la pasarela de QR «{$codigo}».");
        }

        return new QrBanco($codigo, $config);
    }

    public static function estaSimulado(): bool
    {
        return self::pasarela()->codigo() === 'simulado';
    }

    /**
     * Genera un cobro por un importe. Todavía no hay venta: esto se pide con el
     * carrito armado y el cliente esperando con el celular en la mano.
     */
    public static function generar(SesionCaja $sesion, Usuario $usuario, float $monto, ?string $glosa = null): CobroQr
    {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Necesitas una caja abierta para cobrar por QR.');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El importe a cobrar debe ser mayor que cero.');
        }

        $pasarela = self::pasarela();

        $cobro = CobroQr::create([
            'sesion_caja_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'monto' => round($monto, 2),
            'moneda' => Config::get('moneda_codigo', 'BOB'),
            'glosa' => $glosa ?: ('Venta en '.Config::negocio()),
            'pasarela' => $pasarela->codigo(),
            'estado' => CobroQr::PENDIENTE,
            'expira_en' => now()->addMinutes((int) config('qr.minutos_vigencia', self::MINUTOS_POR_DEFECTO)),
        ]);

        $cobro = $pasarela->generar($cobro);

        Auditor::registrar('QR_GENERADO', 'cobros_qr', $cobro->id, [
            'monto' => $cobro->monto,
            'pasarela' => $cobro->pasarela,
        ]);

        return $cobro->fresh();
    }

    /**
     * Pregunta al banco si ya pagaron, y guarda lo que diga.
     *
     * Es lo que llama el mostrador cada pocos segundos mientras el cliente
     * escanea.
     */
    public static function refrescar(CobroQr $cobro): CobroQr
    {
        if (! $cobro->estaPendiente()) {
            return $cobro;
        }

        $estado = self::pasarela()->consultar($cobro);

        if ($estado === $cobro->estado) {
            return $cobro;
        }

        if ($estado === CobroQr::PAGADO) {
            return self::marcarPagado($cobro, 'PASARELA');
        }

        $cobro->update(['estado' => $estado]);

        return $cobro->fresh();
    }

    /**
     * El cajero da por pagado un cobro mirando el comprobante en el celular
     * del cliente.
     *
     * Hace falta: la API del banco se cae, o todavía no hay convenio. Pero es
     * el punto por donde se colaría un cobro que nunca entró, así que queda
     * con nombre y en la bitácora.
     */
    public static function confirmarAMano(CobroQr $cobro, Usuario $usuario, ?string $referencia = null): CobroQr
    {
        if ($cobro->estaPagado()) {
            return $cobro;
        }

        if ($cobro->estado === CobroQr::ANULADO) {
            throw new RuntimeException('Ese cobro fue cancelado: genera uno nuevo.');
        }

        return self::marcarPagado($cobro, 'MANUAL', $usuario, $referencia);
    }

    /** El cajero cancela un QR que ya no se va a usar. */
    public static function anular(CobroQr $cobro, Usuario $usuario): CobroQr
    {
        if ($cobro->estaPagado()) {
            throw new RuntimeException('Ese cobro ya está pagado: no se puede cancelar.');
        }

        $cobro->update(['estado' => CobroQr::ANULADO]);

        Auditor::registrar('QR_ANULADO', 'cobros_qr', $cobro->id, [], $usuario->id);

        return $cobro->fresh();
    }

    /**
     * Ata el cobro a la venta que acaba de registrarse.
     *
     * Con bloqueo de fila y comprobando de nuevo el estado DENTRO de la
     * transacción: si no, dos pestañas del mostrador que cobran a la vez
     * podrían pagar dos ventas distintas con el mismo QR.
     */
    public static function consumir(int $cobroId, Venta $venta): CobroQr
    {
        return DB::transaction(function () use ($cobroId, $venta) {
            $cobro = CobroQr::whereKey($cobroId)->lockForUpdate()->first();

            if (! $cobro) {
                throw new RuntimeException('El cobro por QR no existe.');
            }

            if (! $cobro->estaPagado()) {
                throw new RuntimeException('El cobro por QR todavía no está pagado.');
            }

            if ($cobro->venta_id !== null) {
                throw new RuntimeException('Ese cobro por QR ya se usó en otra venta.');
            }

            $cobro->update(['venta_id' => $venta->id]);

            return $cobro->fresh();
        });
    }

    /**
     * Un aviso del banco: «el cobro tal ya está pagado».
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    public static function procesarAviso(array $datos, array $cabeceras): ?CobroQr
    {
        $pasarela = self::pasarela();

        if (! $pasarela->verificarAviso($datos, $cabeceras)) {
            throw new RuntimeException('Aviso de pago no verificado.');
        }

        $idExterno = $pasarela->idExternoDelAviso($datos);

        if (! $idExterno) {
            return null;
        }

        $cobro = CobroQr::where('pasarela', $pasarela->codigo())
            ->where('id_externo', $idExterno)
            ->first();

        if (! $cobro || ! $cobro->estaPendiente()) {
            return $cobro;
        }

        return self::marcarPagado($cobro, 'PASARELA', null, $datos['referencia'] ?? null);
    }

    private static function marcarPagado(
        CobroQr $cobro,
        string $quien,
        ?Usuario $usuario = null,
        ?string $referencia = null,
    ): CobroQr {
        $cobro->update([
            'estado' => CobroQr::PAGADO,
            'pagado_en' => now(),
            'confirmado_por' => $quien,
            'confirmado_por_id' => $usuario?->id,
            'referencia_bancaria' => $referencia,
        ]);

        Auditor::registrar('QR_PAGADO', 'cobros_qr', $cobro->id, [
            'monto' => $cobro->monto,
            'confirmado_por' => $quien,
            'referencia' => $referencia,
        ], $usuario?->id);

        return $cobro->fresh();
    }
}
