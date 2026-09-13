<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un conteo físico de toda la tienda o de una categoría. Las reglas están en
 * App\Services\TomasInventario.
 */
class TomaInventario extends Model
{
    protected $table = 'tomas_inventario';

    public $timestamps = false;

    protected $fillable = [
        'categoria_id', 'estado', 'observacion',
        'usuario_apertura_id', 'fecha_apertura', 'usuario_cierre_id', 'fecha_cierre',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(TomaInventarioDetalle::class, 'toma_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
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

    /** «Toda la tienda» o el nombre de la categoría que se contó. */
    public function getAlcanceAttribute(): string
    {
        return $this->categoria?->nombre ?? 'Toda la tienda';
    }
}
