<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La factura del proveedor, entera: sus líneas suben el stock y fijan el
 * último costo de cada producto (ver App\Services\Compras).
 */
class Compra extends Model
{
    protected $table = 'compras';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = ['proveedor_id', 'usuario_id', 'documento_externo', 'fecha', 'observacion'];

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

    public function devoluciones(): HasMany
    {
        return $this->hasMany(DevolucionCompra::class, 'compra_id');
    }

    /** Lo que suma la factura. */
    public function getTotalAttribute(): float
    {
        return round((float) $this->detalle->sum('importe'), 2);
    }
}
