<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quien se le compra lo que se vende hecho: la distribuidora de bebidas. */
class Proveedor extends Model
{
    protected $table = 'proveedores';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = ['razon_social', 'documento', 'telefono', 'email', 'direccion', 'activo'];

    /** Recién creado ya está activo, como en la base, sin tener que releerlo. */
    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class, 'proveedor_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('razon_social', 'like', "%{$texto}%")
            ->orWhere('documento', 'like', "%{$texto}%"));
    }
}
