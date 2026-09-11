<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea devuelta al proveedor: qué producto, cuánto y de qué tanda.
 *
 * `lote_id` es lo que distingue esto de un ajuste: cuando se devuelve algo
 * vencido, se devuelve ESE lote, no el que tocaría por orden de salida. Va en
 * NULL para los productos que no llevan control de vencimiento.
 */
class DevolucionCompraDetalle extends Model
{
    protected $table = 'devolucion_compra_detalle';

    public $timestamps = false;

    protected $fillable = [
        'devolucion_compra_id', 'compra_detalle_id', 'producto_id',
        'lote_id', 'cantidad', 'costo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionCompra::class, 'devolucion_compra_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }
}
