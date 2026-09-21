<?php

namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Punto único para dejar rastro de las operaciones sensibles (RNF6).
 */
class Auditor
{
    public static function registrar(
        string $accion,
        ?string $entidad = null,
        int|string|null $entidadId = null,
        ?array $detalle = null,
        ?int $usuarioId = null,
    ): void {
        Auditoria::create([
            'usuario_id' => $usuarioId ?? Auth::id(),
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'detalle' => $detalle,
            'ip' => Request::ip(),
            'fecha' => now(),
        ]);
    }
}
