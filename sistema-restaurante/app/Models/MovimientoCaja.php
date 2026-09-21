<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entradas y salidas de efectivo que no son ventas: un adelanto, la compra
 * de una bolsa de hielo, el retiro parcial del dueño.
 */
class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    public $timestamps = false;

    protected $fillable = ['sesion_caja_id', 'usuario_id', 'tipo', 'concepto', 'monto', 'fecha'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
