<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea devuelta, apuntando a la línea original de la venta.
 *
 * `reingresa_stock` es la diferencia entre mercadería que vuelve al estante y
 * mercadería que vino rota: en el segundo caso se devuelve el dinero pero el
 * producto no se puede volver a vender, así que el stock no sube.
 *
 * El régimen de impuesto lo copia de la línea de venta original un trigger
 * BEFORE INSERT —la tasa de hoy puede no ser la de aquel día—, y de ahí salen
 * las columnas generadas `impuesto_linea` y `total_linea`.
 *
 * Al insertarse, el trigger `trg_devolucion_detalle_after_insert` acumula lo
 * devuelto en la venta, recalcula totales y estado, y mueve el kardex.
 */
class DevolucionDetalle extends Model
{
    protected $table = 'devolucion_detalle';

    public $timestamps = false;

    // Igual que en VentaDetalle: el régimen de impuesto lo copia un trigger,
    // pero tiene que ser asignable para la vía sin triggers.
    protected $fillable = [
        'devolucion_id', 'venta_detalle_id', 'producto_id',
        'cantidad', 'precio_unitario', 'reingresa_stock',
        'afecto_impuesto', 'tasa_impuesto', 'impuesto_incluido',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
            'tasa_impuesto' => 'decimal:4',
            'impuesto_linea' => 'decimal:2',
            'total_linea' => 'decimal:2',
            'afecto_impuesto' => 'boolean',
            'reingresa_stock' => 'boolean',
        ];
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class, 'devolucion_id');
    }

    public function ventaDetalle(): BelongsTo
    {
        return $this->belongsTo(VentaDetalle::class, 'venta_detalle_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
