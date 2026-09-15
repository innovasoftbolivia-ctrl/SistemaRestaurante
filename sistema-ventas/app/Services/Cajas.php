<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura, movimientos y cierre del turno de caja.
 *
 * El arqueo lo calcula `sp_cerrar_caja` en la base: es la misma fórmula para
 * todos y no depende de que la aplicación la recuerde bien.
 */
class Cajas
{
    /** Reintentos ante un deadlock; mismo criterio que `Ventas::REINTENTOS`. */
    private const REINTENTOS = 3;

    /** La sesión abierta del usuario, si la tiene. */
    public static function sesionDe(Usuario $usuario): ?SesionCaja
    {
        return SesionCaja::abiertas()
            ->where('usuario_apertura_id', $usuario->id)
            ->with('caja')
            ->first();
    }

    public static function abrir(Caja $caja, Usuario $usuario, float $montoInicial, ?string $observacion = null): SesionCaja
    {
        if ($montoInicial < 0) {
            throw new RuntimeException('El monto inicial no puede ser negativo.');
        }

        // Estos dos chequeos son solo para dar un mensaje entendible en el
        // caso normal (sin carrera): el candado real que evita dos sesiones
        // abiertas —a la vez en la misma caja, o a la vez para el mismo
        // usuario en dos cajas distintas— son los índices únicos
        // `uq_sesion_caja_abierta` / `uq_sesion_usuario_abierta` de la base.
        // Un SELECT-antes-de-INSERT en PHP, sin más, no cierra la ventana de
        // carrera entre dos peticiones simultáneas.
        if (self::sesionDe($usuario)) {
            throw new RuntimeException('Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
        }

        if ($caja->sesionAbierta()->exists()) {
            throw new RuntimeException("La {$caja->nombre} ya está abierta por otro usuario.");
        }

        try {
            $sesion = DB::transaction(fn () => SesionCaja::create([
                'caja_id' => $caja->id,
                'usuario_apertura_id' => $usuario->id,
                'fecha_apertura' => now(),
                'monto_inicial' => $montoInicial,
                'estado' => 'ABIERTA',
                'observacion' => $observacion,
            ]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uq_sesion_usuario_abierta')) {
                throw new RuntimeException('Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
            }
            if (str_contains($e->getMessage(), 'uq_sesion_caja_abierta')) {
                throw new RuntimeException("La {$caja->nombre} ya está abierta por otro usuario.");
            }
            throw $e;
        }

        Auditor::registrar('CAJA_ABIERTA', 'sesiones_caja', $sesion->id, [
            'caja' => $caja->nombre,
            'monto_inicial' => $montoInicial,
        ], $usuario->id);

        return $sesion;
    }

    public static function movimiento(
        SesionCaja $sesion,
        Usuario $usuario,
        string $tipo,
        string $concepto,
        float $monto,
    ): MovimientoCaja {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('La caja ya está cerrada: no admite más movimientos.');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El monto del movimiento debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($sesion, $usuario, $tipo, $concepto, $monto) {
            // El turno bloqueado: dos egresos a la vez no pueden sacar entre
            // los dos más de lo que hay.
            $bloqueada = SesionCaja::whereKey($sesion->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaAbierta()) {
                throw new RuntimeException('La caja ya está cerrada: no admite más movimientos.');
            }

            if ($tipo === 'EGRESO') {
                self::validarEgreso($bloqueada, $usuario, $monto);
            }

            return self::registrarMovimiento($bloqueada, $usuario, $tipo, $concepto, $monto);
        }, self::REINTENTOS);
    }

    /**
     * Un egreso no puede sacar más de lo que debería haber en el cajón, y el
     * cajero tiene un tope sin autorización.
     *
     * Sin esto, un faltante se tapaba con un egreso por el monto justo —«Compra
     * de bolsas, 80»— y el arqueo cerraba en cero. Por encima del tope, el
     * egreso lo registra quien puede cerrar la caja, en el mismo turno.
     */
    private static function validarEgreso(SesionCaja $sesion, Usuario $usuario, float $monto): void
    {
        $disponible = round($sesion->efectivoEsperado(), 2);

        if (round($monto, 2) > $disponible) {
            throw new RuntimeException(sprintf(
                'El egreso (%s) es mayor que el efectivo que debería haber en el cajón (%s).',
                Config::importe($monto),
                Config::importe(max(0, $disponible)),
            ));
        }

        $tope = (float) Config::get('egreso_max_cajero', '0');

        if (! $usuario->tienePermiso('caja.cerrar') && round($monto, 2) > $tope) {
            throw new RuntimeException(sprintf(
                'Tu rol puede registrar egresos de hasta %s. Para uno mayor, pide a un administrador que lo registre en tu turno.',
                Config::importe($tope),
            ));
        }
    }

    private static function registrarMovimiento(
        SesionCaja $sesion,
        Usuario $usuario,
        string $tipo,
        string $concepto,
        float $monto,
    ): MovimientoCaja {
        $movimiento = MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
            'fecha' => now(),
        ]);

        Auditor::registrar('CAJA_MOVIMIENTO', 'movimientos_caja', $movimiento->id, [
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
        ], $usuario->id);

        return $movimiento;
    }

    /**
     * Cierra el turno con el efectivo contado. El procedimiento calcula el
     * esperado y la base deriva la diferencia.
     */
    public static function cerrar(
        SesionCaja $sesion,
        Usuario $usuario,
        float $declarado,
        ?string $observacion = null,
    ): SesionCaja {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Esta caja ya fue cerrada.');
        }

        if ($declarado < 0) {
            throw new RuntimeException('El efectivo contado no puede ser negativo.');
        }

        // Una diferencia sin explicación no le sirve a nadie. Se calcula aquí
        // con la misma fórmula que firma el procedimiento.
        $diferencia = round($declarado - $sesion->efectivoEsperado(), 2);

        if ($diferencia !== 0.0 && blank($observacion)) {
            throw new RuntimeException(sprintf(
                'El conteo tiene una diferencia de %s%s: escribe en la observación qué pasó.',
                $diferencia > 0 ? '+' : '−',
                Config::importe(abs($diferencia)),
            ));
        }

        // `sp_cerrar_caja` hace su SELECT ... FOR UPDATE y su UPDATE final en
        // sentencias separadas: sin envolverlo en una transacción de verdad,
        // el autocommit de MySQL libera ese lock apenas termina el SELECT, no
        // al terminar el procedimiento. Dos cierres de la misma sesión que se
        // solapan (doble clic, dos administradores) podían pasar ambos el
        // chequeo de "¿sigue abierta?" y terminar pisándose en silencio —
        // "last write wins" sin ningún error para nadie. Envuelto en
        // `DB::transaction()`, el lock se mantiene hasta el commit: el
        // segundo cierre que llegue queda bloqueado hasta que el primero
        // termine, y al reintentar ya encuentra la sesión `CERRADA` — el
        // propio procedimiento lo rechaza con el SIGNAL que ya tenía.
        //
        // `DB::select` y no `DB::statement`: el procedimiento termina con un
        // SELECT del arqueo, y ese resultado hay que consumirlo o la siguiente
        // consulta de la conexión falla.
        DB::transaction(fn () => ReglasEnPhp::activa()
            ? ReglasEnPhp::cerrarCaja($sesion->id, $usuario->id, $declarado, $observacion)
            : DB::select('CALL sp_cerrar_caja(?, ?, ?, ?)', [
                $sesion->id, $usuario->id, $declarado, $observacion,
            ]), self::REINTENTOS);

        $sesion->refresh();

        Auditor::registrar('CAJA_CERRADA', 'sesiones_caja', $sesion->id, [
            'esperado' => $sesion->monto_esperado,
            'declarado' => $sesion->monto_declarado,
            'diferencia' => $sesion->diferencia,
        ], $usuario->id);

        return $sesion;
    }
}
