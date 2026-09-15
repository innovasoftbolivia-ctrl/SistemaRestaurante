<?php

namespace App\Support;

use App\Models\Usuario;

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

    public const MENSAJE = 'Ese cambio dejaría el sistema sin ninguna cuenta que pueda administrar usuarios. Asigna antes ese permiso a otra cuenta activa.';
}
