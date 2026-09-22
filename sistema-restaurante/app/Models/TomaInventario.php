<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contar la bodega de una vez. Cada línea guarda lo que decía el sistema al
 * contarla, y el cierre aplica la DIFERENCIA: el local sigue vendiendo
 * mientras se cuenta (ver App\Services\TomasInventario).
 */
class TomaInventario extends Model
{
    protected $table = 'tomas_inventario';

    public $timestamps = false;

    protected $fillable = [
        'estado', 'observacion', 'usuario_apertura_id', 'fecha_apertura', 'usuario_cierre_id', 'fecha_cierre',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(TomaInventarioDetalle::class, 'toma_id');
    }

    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_apertura_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_cierre_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'ABIERTA';
    }
}
