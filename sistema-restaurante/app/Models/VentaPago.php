<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cómo se pagó. Una venta admite varias formas a la vez (pago mixto).
 * `monto_recibido` solo tiene sentido en efectivo; `vuelto` es columna
 * generada y sale de la diferencia.
 */
class VentaPago extends Model
{
    protected $table = 'venta_pagos';

    public $timestamps = false;

    protected $fillable = ['venta_id', 'metodo_pago_id', 'monto', 'monto_recibido', 'referencia'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'monto_recibido' => 'decimal:2',
            'vuelto' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }
}
