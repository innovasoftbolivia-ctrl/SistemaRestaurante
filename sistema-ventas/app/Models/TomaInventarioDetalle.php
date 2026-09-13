<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto dentro de una toma de inventario. `diferencia` la calcula la
 * base: contado − stock_sistema.
 */
class TomaInventarioDetalle extends Model
{
    protected $table = 'toma_inventario_detalle';

    public $timestamps = false;

    protected $fillable = [
        'toma_id', 'producto_id', 'contado', 'stock_sistema', 'costo_unitario',
        'usuario_id', 'fecha_conteo', 'movimiento_id',
    ];

    protected function casts(): array
    {
        return [
            'contado' => 'decimal:3',
            'stock_sistema' => 'decimal:3',
            'diferencia' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'fecha_conteo' => 'datetime',
        ];
    }

    public function toma(): BelongsTo
    {
        return $this->belongsTo(TomaInventario::class, 'toma_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoInventario::class, 'movimiento_id');
    }

    public function estaContada(): bool
    {
        return $this->contado !== null;
    }

    /** Lo que vale la diferencia al costo: negativo es mercadería que falta. */
    public function getValorDiferenciaAttribute(): float
    {
        return round((float) $this->diferencia * (float) $this->costo_unitario, 2);
    }
}
