<?php

namespace App\Support;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;

/**
 * Que el sistema nunca se quede sin nadie que pueda administrar usuarios.
 *
 * Quitarle `usuarios.gestionar` al propio rol, desactivarlo o dar de baja al
 * propio empleado dejaba al negocio sin administración, y la única salida era
 * entrar a la base por SQL. Antes de cualquiera de esos cambios se cuenta
 * cuántas cuentas administradoras quedarían.
 */
class Administracion
{
    public const PERMISO = 'usuarios.gestionar';

    /**
     * Cuentas que pueden administrar usuarios, simulando un cambio: sin
     * contar las del rol `$rolSinPermiso` ni la del empleado `$empleadoFuera`.
     */
    public static function restantes(?int $rolSinPermiso = null, ?int $empleadoFuera = null): int
    {
        return Usuario::query()
            ->where('activo', 1)
            ->when($empleadoFuera, fn ($q, $id) => $q->where('empleado_id', '<>', $id))
            ->whereHas('empleado', fn ($q) => $q->where('estado', 'ACTIVO'))
            ->whereHas('rol', fn ($q) => $q->where('activo', 1)
                ->when($rolSinPermiso, fn ($r, $id) => $r->where('roles.id', '<>', $id))
                ->whereHas('permisos', fn ($p) => $p->where('codigo', self::PERMISO)))
            ->count();
    }

    /**
     * Si `$permisoIds` incluye algún permiso que la cuenta en sesión no tiene.
     *
     * Quien administra usuarios no puede repartir más de lo que tiene: sin
     * esto, un «Supervisor» con `usuarios.gestionar` se agregaba todos los
     * permisos a su propio rol, o se ponía el de Administrador, en un clic.
     */
    public static function otorgaDeMas(array $permisoIds): bool
    {
        $actor = Auth::user();
        $propios = $actor?->rol?->activo ? $actor->rol->permisos->pluck('id')->all() : [];

        return array_diff(array_map('intval', $permisoIds), array_map('intval', $propios)) !== [];
    }

    /** Si el rol tiene algún permiso que la cuenta en sesión no tiene. */
    public static function rolPorEncima(?Rol $rol): bool
    {
        return $rol !== null && self::otorgaDeMas($rol->permisos()->pluck('permisos.id')->all());
    }

    public const MENSAJE_POR_ENCIMA = 'No puedes otorgar permisos que tu propio rol no tiene, ni administrar cuentas o roles con más permisos que el tuyo.';

    public const MENSAJE = 'Ese cambio dejaría el sistema sin ninguna cuenta que pueda administrar usuarios. Asigna antes ese permiso a otra cuenta activa.';
}
