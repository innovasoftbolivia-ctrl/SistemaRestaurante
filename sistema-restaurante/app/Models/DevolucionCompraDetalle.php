<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevolucionCompraDetalle extends Model
{
    protected $table = 'devolucion_compra_detalle';

    public $timestamps = false;

    protected $fillable = ['devolucion_compra_id', 'compra_detalle_id', 'producto_id', 'cantidad', 'cantidad_repuesta', 'costo_unitario'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_repuesta' => 'decimal:3',
            'costo_unitario' => 'decimal:4',
            'importe' => 'decimal:2',
        ];
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionCompra::class, 'devolucion_compra_id');
    }

    public function lineaCompra(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Lo que el proveedor todavía debe reponer de esta línea. */
    public function getPorReponerAttribute(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_repuesta, 3);
    }
}
