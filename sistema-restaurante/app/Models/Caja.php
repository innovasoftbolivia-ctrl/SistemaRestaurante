<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Caja extends Model
{
    protected $table = 'cajas';

    public $timestamps = false;

    protected $fillable = ['nombre', 'ubicacion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionCaja::class, 'caja_id');
    }

    /** La base garantiza con un índice único que solo haya una abierta. */
    public function sesionAbierta(): HasOne
    {
        return $this->hasOne(SesionCaja::class, 'caja_id')->where('estado', 'ABIERTA');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }
}
