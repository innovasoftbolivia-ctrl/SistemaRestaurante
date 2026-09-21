<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol de acceso al sistema (Administrador, Cajero, Cocina).
 * Define qué puede hacer la cuenta; es independiente del cargo laboral.
 */
class Rol extends Model
{
    protected $table = 'roles';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'rol_permiso', 'rol_id', 'permiso_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'rol_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', 1);
    }
}
