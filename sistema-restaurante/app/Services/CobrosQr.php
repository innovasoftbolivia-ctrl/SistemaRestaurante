<?php

namespace App\Services;

use App\Models\CobroQr;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Qr\PasarelaQr;
use App\Services\Qr\QrBanco;
use App\Services\Qr\QrBaneco;
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
 * Esa guarda no vive aquí sino en `Ventas::registrar()` (`cobroQrDelPago`),
 * con la fila del cobro bloqueada dentro de la transacción de la venta: dos
 * pestañas del mostrador cobrando a la vez no pueden gastar el mismo QR dos
 * veces, y si algo no cuadra se deshace la venta entera.
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

        return $codigo === QrBaneco::CODIGO ? new QrBaneco($config) : new QrBanco($codigo, $config);
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
        if ($monto <= 0) {
            throw new RuntimeException('El importe a cobrar debe ser mayor que cero.');
        }

        $pasarela = self::pasarela();

        // El turno, releído con candado compartido DENTRO de una transacción:
        // fuera de ella el candado se soltaba al instante y un QR podía nacer
        // colgado de una caja que se estaba cerrando.
        $cobro = DB::transaction(function () use ($sesion, $usuario, $monto, $glosa, $pasarela) {
            $turno = SesionCaja::whereKey($sesion->id)->sharedLock()->first();

            if (! $turno?->estaAbierta()) {
                throw new RuntimeException('Necesitas una caja abierta para cobrar por QR.');
            }

            return CobroQr::create([
                'sesion_caja_id' => $turno->id,
                'usuario_id' => $usuario->id,
                'monto' => round($monto, 2),
                'moneda' => Config::get('moneda_codigo', 'BOB'),
                'glosa' => $glosa ?: ('Venta en '.Config::negocio()),
                'pasarela' => $pasarela->codigo(),
                'estado' => CobroQr::PENDIENTE,
                'expira_en' => now()->addMinutes((int) config('qr.minutos_vigencia', self::MINUTOS_POR_DEFECTO)),
            ]);
        });

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

        $pasarela = self::pasarela();
        $estado = $pasarela->consultar($cobro);

        if ($estado === $cobro->estado) {
            return $cobro;
        }

        if ($estado === CobroQr::PAGADO) {
            return self::marcarPagado($cobro, 'PASARELA', null, $pasarela->referenciaDelPago());
        }

        // Solo si sigue pendiente: el aviso del banco, el sondeo del mostrador
        // y la confirmación a mano pueden llegar a la vez, y el primero gana.
        self::siSiguePendiente($cobro, ['estado' => $estado]);

        return $cobro->fresh();
    }

    /**
     * Cambia el cobro solo si sigue PENDIENTE, en un único UPDATE con esa
     * condición: sin leer y después escribir, que dejaba pisar un PAGADO.
     * Devuelve si lo cambió.
     *
     * @param  array<string, mixed>  $cambios
     */
    private static function siSiguePendiente(CobroQr $cobro, array $cambios): bool
    {
        return CobroQr::whereKey($cobro->id)
            ->where('estado', CobroQr::PENDIENTE)
            ->update($cambios) === 1;
    }

    /**
     * El cajero da por pagado un cobro mirando el comprobante en el celular
     * del cliente.
     *
     * Con el simulador es la única forma. Con un banco conectado, primero se
     * le pregunta al banco: si ya lo registra, queda pagado por el banco; si
     * dice que sigue pendiente, NO se acepta la palabra del cajero —es
     * justamente el caso de un comprobante falso o de una transferencia que no
     * llegó—. Solo si el banco no responde se permite confirmar a mano, y
     * queda con nombre y en la bitácora.
     */
    public static function confirmarAMano(CobroQr $cobro, Usuario $usuario, ?string $referencia = null): CobroQr
    {
        if ($cobro->estaPagado()) {
            return $cobro;
        }

        if ($cobro->estado === CobroQr::ANULADO) {
            throw new RuntimeException('Ese cobro fue cancelado: genera uno nuevo.');
        }

        // Un QR vencido ya no se puede pagar: darlo por pagado a mano sería
        // aceptar un comprobante que no puede existir.
        if ($cobro->estado === CobroQr::EXPIRADO) {
            throw new RuntimeException('El QR venció sin que se registrara el pago. Genera uno nuevo.');
        }

        if (! self::estaSimulado()) {
            try {
                $cobro = self::refrescar($cobro);
            } catch (RuntimeException) {
                // El banco no contesta: se sigue con la confirmación a mano,
                // pero no sobre un QR cuyo plazo ya pasó.
                if ($cobro->expira_en !== null && $cobro->expira_en->isPast()) {
                    throw new RuntimeException('El banco no responde y el QR ya venció: cobra por otro medio.');
                }

                return self::marcarPagado($cobro, 'MANUAL', $usuario, $referencia);
            }

            if ($cobro->estaPagado()) {
                return $cobro;
            }

            throw new RuntimeException(match ($cobro->estado) {
                CobroQr::EXPIRADO => 'El QR venció sin que el banco registrara el pago. Genera uno nuevo.',
                CobroQr::ANULADO => 'Ese cobro fue cancelado: genera uno nuevo.',
                default => 'El banco todavía no registra este pago. Espera unos segundos; si el cliente insiste en que pagó, pídele el comprobante y vuelve a verificar.',
            });
        }

        return self::marcarPagado($cobro, 'MANUAL', $usuario, $referencia);
    }

    /** El cajero cancela un QR que ya no se va a usar. */
    /**
     * Da por vencido un cobro que ya pasó su hora: primero se cancela en el
     * banco —donde el QR puede seguir vivo— y después se marca aquí.
     */
    public static function vencer(CobroQr $cobro): CobroQr
    {
        // Releído con candado: un aviso del banco que llega en el mismo
        // instante no se pisa con el vencimiento.
        $cobro = DB::transaction(function () use ($cobro) {
            $bloqueado = CobroQr::whereKey($cobro->id)->lockForUpdate()->first() ?? $cobro;

            if (! $bloqueado->estaPendiente()) {
                return $bloqueado;
            }

            self::pasarela()->anular($bloqueado);
            $bloqueado->update(['estado' => CobroQr::EXPIRADO]);

            return $bloqueado;
        });

        if ($cobro->estado !== CobroQr::EXPIRADO) {
            return $cobro;
        }

        Auditor::registrar('COBRO_QR_VENCIDO', 'cobros_qr', $cobro->id, [
            'monto' => $cobro->monto,
            'expiro_en' => $cobro->expira_en?->toDateTimeString(),
        ], $cobro->usuario_id);

        return $cobro->fresh();
    }

    public static function anular(CobroQr $cobro, Usuario $usuario): CobroQr
    {
        if ($cobro->estaPagado()) {
            throw new RuntimeException('Ese cobro ya está pagado: no se puede cancelar.');
        }

        if ($cobro->estado === CobroQr::ANULADO) {
            return $cobro;
        }

        // En el banco primero: un QR que queda vivo allá se puede pagar después,
        // cuando ya no hay venta esperándolo.
        if ($cobro->estaPendiente()) {
            try {
                self::pasarela()->anular($cobro);
            } catch (RuntimeException $e) {
                // Puede que no se anule porque justo lo pagaron: se pregunta.
                if (self::refrescar($cobro)->estaPagado()) {
                    throw new RuntimeException('El cliente ya pagó ese QR: no se puede cancelar. Úsalo para cobrar.');
                }

                throw $e;
            }
        }

        // Nunca sobre un PAGADO: si el banco lo marcó pagado entre la consulta
        // y aquí, el dinero entró y el cobro tiene que seguir a la vista.
        $cambiado = CobroQr::whereKey($cobro->id)
            ->whereNotIn('estado', [CobroQr::PAGADO, CobroQr::ANULADO])
            ->update(['estado' => CobroQr::ANULADO]) === 1;

        if (! $cambiado && $cobro->fresh()?->estaPagado()) {
            throw new RuntimeException('El cliente ya pagó ese QR: no se puede cancelar. Úsalo para cobrar.');
        }

        if (! $cambiado) {
            return $cobro->fresh();
        }

        Auditor::registrar('QR_ANULADO', 'cobros_qr', $cobro->id, [], $usuario->id);

        return $cobro->fresh();
    }

    /**
     * Un aviso del banco: «el cobro tal ya está pagado».
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    public static function procesarAviso(array $datos, array $cabeceras, string $cuerpo = ''): ?CobroQr
    {
        $pasarela = self::pasarela();

        if (! $pasarela->verificarAviso($datos, $cabeceras, $cuerpo)) {
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

        // El aviso no marca nada por sí mismo: dispara una consulta al banco y
        // vale lo que el banco conteste. Así un aviso falsificado —o uno de un
        // banco que no firma, como Banco Económico— no puede dar por pagado un
        // cobro que no se pagó.
        return self::refrescar($cobro);
    }

    private static function marcarPagado(
        CobroQr $cobro,
        string $quien,
        ?Usuario $usuario = null,
        ?string $referencia = null,
    ): CobroQr {
        $pagado = self::siSiguePendiente($cobro, [
            'estado' => CobroQr::PAGADO,
            'pagado_en' => now(),
            'confirmado_por' => $quien,
            'confirmado_por_id' => $usuario?->id,
            'referencia_bancaria' => $referencia,
        ]);

        // Otro llegó primero (el aviso del banco y el sondeo a la vez): el
        // pago ya quedó registrado una vez, con quien lo registró.
        if (! $pagado) {
            $actual = $cobro->fresh();

            if ($actual?->estaPagado()) {
                return $actual;
            }

            throw new RuntimeException('Ese cobro ya no está pendiente: genera uno nuevo.');
        }

        Auditor::registrar('QR_PAGADO', 'cobros_qr', $cobro->id, [
            'monto' => $cobro->monto,
            'confirmado_por' => $quien,
            'referencia' => $referencia,
        ], $usuario?->id);

        return $cobro->fresh();
    }
}
