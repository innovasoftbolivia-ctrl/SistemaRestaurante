<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraDetalle extends Model
{
    protected $table = 'compra_detalle';

    public $timestamps = false;

    protected $fillable = ['compra_id', 'producto_id', 'cantidad', 'cantidad_devuelta', 'costo_unitario'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_devuelta' => 'decimal:3',
            'costo_unitario' => 'decimal:4',
            'importe' => 'decimal:2',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Lo que todavía se le puede devolver al proveedor de esta línea. */
    public function getDevolvibleAttribute(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 3);
    }
}
