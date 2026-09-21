<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cargo: la función laboral de la persona dentro del negocio
 * (Gerente, Cajero, Cocinero...). No confundir con el rol de acceso
 * al sistema, que vive en {@see Rol}.
 */
class Cargo extends Model
{
    protected $table = 'cargos';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class, 'cargo_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', 1);
    }
}
