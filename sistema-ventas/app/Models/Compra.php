<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de una compra a proveedor: una guía o factura, con sus líneas en
 * `compra_detalle`. `proveedor_id` y `documento_externo` viven aquí, no
 * repetidos en cada línea de kardex (2FN).
 */
class Compra extends Model
{
    protected $table = 'compras';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'proveedor_id', 'usuario_id', 'documento_externo', 'fecha', 'observacion',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'datetime'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(CompraDetalle::class, 'compra_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'compra_id');
    }

    /** Lo que de esta factura se le terminó devolviendo al proveedor. */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(DevolucionCompra::class, 'compra_id');
    }

    public function getTotalAttribute(): float
    {
        return round((float) $this->detalle->sum('importe'), 2);
    }
}
