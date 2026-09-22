<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TomaInventarioDetalle extends Model
{
    protected $table = 'toma_inventario_detalle';

    public $timestamps = false;

    protected $fillable = [
        'toma_id', 'producto_id', 'contado', 'stock_sistema', 'costo_unitario', 'usuario_id', 'fecha_conteo', 'movimiento_id',
    ];

    protected function casts(): array
    {
        return [
            'contado' => 'decimal:3',
            'stock_sistema' => 'decimal:3',
            'diferencia' => 'decimal:3',
            'costo_unitario' => 'decimal:4',
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
}
