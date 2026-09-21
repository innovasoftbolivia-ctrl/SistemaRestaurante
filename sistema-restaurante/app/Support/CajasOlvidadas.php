<?php

namespace App\Support;

use App\Models\SesionCaja;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Turnos de caja que siguen abiertos cuando ya no deberían.
 *
 * Un turno abierto hace dos días no se nota justamente porque nadie entra a la
 * pantalla de caja a buscarlo, y para entonces el arqueo ya no sirve: el
 * efectivo esperado mezcla dos jornadas y cualquier diferencia es imposible de
 * explicar. Por eso el aviso sale en TODAS las pantallas, hasta que se cierre.
 */
class CajasOlvidadas
{
    /**
     * Doce horas: más que cualquier jornada de mostrador razonable, y menos
     * que un turno que cruzó la noche. Pasado esto, el turno ya no es un turno
     * largo sino uno que alguien se olvidó de cerrar.
     */
    public const HORAS = 12;

    /**
     * Lo que le corresponde ver a cada quien: todos los turnos olvidados a quien
     * puede cerrarlos, y solo el propio al cajero, que no puede cerrarlo pero sí
     * avisar. El resto no ve nada: no tiene nada que hacer al respecto.
     *
     * @return Collection<int, SesionCaja>
     */
    public static function para(?Usuario $usuario): Collection
    {
        if (! $usuario) {
            return collect();
        }

        $consulta = SesionCaja::query()
            ->with('caja:id,nombre', 'usuarioApertura:id,usuario')
            // Por `caja_abierta_uk` y no por `estado`: su índice único solo
            // tiene los turnos abiertos (uno por caja), mientras que buscar por
            // fecha recorría todo el historial. Corre en cada pantalla.
            ->whereNotNull('caja_abierta_uk')
            ->where('fecha_apertura', '<', now()->subHours(self::HORAS))
            ->orderBy('fecha_apertura');

        if (! $usuario->tienePermiso('caja.cerrar')) {
            $consulta->where('usuario_apertura_id', $usuario->id);
        }

        return $consulta->get();
    }
}
