<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $table = 'categorias';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    /**
     * `pasa_por_cocina`: si sus platos se preparan en la cocina. Las bebidas
     * no: se cobran igual, pero no llenan la pantalla de la cocina ni la
     * comanda con gaseosas que nadie cocina.
     */
    protected $fillable = ['nombre', 'descripcion', 'activo', 'pasa_por_cocina'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'pasa_por_cocina' => 'boolean'];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }
}
