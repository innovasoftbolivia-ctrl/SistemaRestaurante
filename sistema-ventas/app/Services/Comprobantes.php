<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Usuario;
use App\Models\Venta;
use App\Support\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sustitución del documento de una venta ya cobrada (HU-42).
 *
 * El caso típico: se entregó un recibo y el cliente vuelve pidiendo factura.
 * No se toca la venta ni el stock —el dinero ya se cobró—: solo cambia el
 * documento. El anterior queda SUSTITUIDO, nunca se borra, y el nuevo lo
 * referencia en `sustituye_a`, así que la cadena queda auditable.
 *
 * `sp_sustituir_comprobante` hace el trabajo: valida el estado, el plazo,
 * libera el índice de documento vigente, toma el nuevo correlativo y deja su
 * propia entrada en `auditoria`.
 */
class Comprobantes
{
    /** Días que la configuración da de plazo para sustituir. */
    public static function plazoDias(): int
    {
        return (int) Config::get('dias_max_sustitucion', '1');
    }

    /** Último día en que este documento todavía se puede sustituir. */
    public static function venceEl(Venta $venta): Carbon
    {
        return $venta->fecha->copy()->addDays(self::plazoDias())->endOfDay();
    }

    /**
     * Días transcurridos con el mismo criterio que el `DATEDIFF` del
     * procedimiento: días de calendario, no períodos de 24 horas. Una venta de
     * las 23:59 de ayer lleva un día a las 00:01 de hoy.
     */
    private static function diasTranscurridos(Venta $venta): int
    {
        return (int) $venta->fecha->copy()->startOfDay()->diffInDays(now()->startOfDay(), false);
    }

    /**
     * Las mismas condiciones que comprueba el procedimiento, para poder
     * enseñar u ocultar la acción antes de intentarla.
     */
    public static function puedeSustituirse(Comprobante $comprobante): bool
    {
        $venta = $comprobante->venta;

        return $comprobante->estado === 'EMITIDO'
            && $venta?->estado === 'COMPLETADA'
            && self::diasTranscurridos($venta) <= self::plazoDias();
    }

    /** Por qué no se puede, en palabras entendibles. */
    public static function motivoBloqueo(Comprobante $comprobante): ?string
    {
        $venta = $comprobante->venta;

        return match (true) {
            $comprobante->estado === 'SUSTITUIDO' => 'Este documento ya fue sustituido por otro.',
            $comprobante->estado === 'ANULADO' => 'Este documento está anulado.',
            $venta?->estado === 'ANULADA' => 'La venta está anulada: su documento no se sustituye.',
            in_array($venta?->estado, ['DEVUELTA', 'DEVUELTA_PARCIAL'], true) => 'La venta tiene devoluciones: su documento no se sustituye.',
            $venta && self::diasTranscurridos($venta) > self::plazoDias() => 'Pasó el plazo de '.self::plazoDias().' día(s) para sustituir el documento de esta venta.',
            default => null,
        };
    }

    /**
     * Emite el documento que reemplaza al actual.
     *
     * `$cliente` es a quién queda asociada la venta: una persona jurídica hace
     * que salga factura; sin cliente o con persona natural, recibo.
     */
    /** ¿El documento lleva los mismos datos que tiene hoy la ficha del cliente? */
    private static function datosCongeladosVigentes(Comprobante $comprobante, ?Cliente $cliente): bool
    {
        if (! $cliente) {
            return true;
        }

        $normalizar = fn (?string $texto) => mb_strtolower(trim((string) $texto));

        return $normalizar($comprobante->cliente_nombre) === $normalizar($cliente->nombre)
            && $normalizar($comprobante->cliente_documento) === $normalizar($cliente->documento)
            && $normalizar($comprobante->cliente_direccion) === $normalizar($cliente->direccion);
    }

    public static function sustituir(
        Comprobante $comprobante,
        Usuario $usuario,
        ?Cliente $cliente,
        string $motivo,
    ): Comprobante {
        $venta = $comprobante->venta;

        if (! $venta) {
            throw new RuntimeException('El documento no tiene una venta asociada.');
        }

        if ($bloqueo = self::motivoBloqueo($comprobante)) {
            throw new RuntimeException($bloqueo);
        }

        $serie = Ventas::seriePara($cliente);

        // Idéntico de verdad: misma serie, mismo cliente Y los datos que quedaron
        // congelados en el documento siguen siendo los de la ficha. Corregir el
        // NIT o la razón social del mismo cliente es la causa más común de
        // sustitución, y comparando solo ids quedaba bloqueada.
        if ($serie->id === $comprobante->serie_id
            && $cliente?->id === $venta->cliente_id
            && self::datosCongeladosVigentes($comprobante, $cliente)) {
            throw new RuntimeException(
                'El documento nuevo saldría idéntico al actual. Elige otro cliente, o corrige primero sus datos en la ficha del cliente.'
            );
        }

        return DB::transaction(function () use ($comprobante, $venta, $usuario, $cliente, $serie, $motivo) {
            /*
             * El procedimiento sabe asignar un cliente, pero no quitarlo (su
             * parámetro NULL significa «no cambies nada»). Para poder pasar de
             * factura a recibo el cambio se hace aquí y el procedimiento se
             * llama ya con la venta apuntando a quien corresponde.
             */
            if ($cliente?->id !== $venta->cliente_id) {
                Venta::whereKey($venta->id)->update(['cliente_id' => $cliente?->id]);
            }

            // El procedimiento ya escribe su propia entrada en `auditoria`.
            if (ReglasEnPhp::activa()) {
                [$id] = ReglasEnPhp::sustituirComprobante(
                    $comprobante->id, $serie->id, null, $usuario->id, $motivo
                );
            } else {
                DB::statement('CALL sp_sustituir_comprobante(?, ?, NULL, ?, ?, @nuevo_id, @numero)', [
                    $comprobante->id, $serie->id, $usuario->id, $motivo,
                ]);

                $id = DB::selectOne('SELECT @nuevo_id AS id')->id;
            }

            if (! $id) {
                throw new RuntimeException('No se pudo emitir el documento de reemplazo.');
            }

            return Comprobante::with('serie.tipo')->findOrFail($id);
        });
    }
}
