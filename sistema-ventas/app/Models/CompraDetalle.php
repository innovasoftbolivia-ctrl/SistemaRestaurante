<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de compra: cantidad y costo de un producto dentro de una guía o
 * factura de proveedor. `importe` es columna generada (3FN), igual que en
 * `venta_detalle`.
 */
class CompraDetalle extends Model
{
    protected $table = 'compra_detalle';

    public $timestamps = false;

    protected $fillable = [
        'compra_id', 'producto_id', 'cantidad', 'costo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_devuelta' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
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

    /** Cuánto de esta línea queda todavía sin devolver al proveedor. */
    public function getPendienteDevolucionAttribute(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 3);
    }
}
