<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del kardex: qué entró o salió, por qué, y el stock antes y
 * después. Lo escribe solo App\Services\Inventario.
 */
class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    public $timestamps = false;

    public const ORIGENES = [
        'INICIAL' => 'Stock inicial',
        'COMPRA' => 'Compra',
        'VENTA' => 'Venta',
        'ANULACION' => 'Venta anulada',
        'AJUSTE' => 'Ajuste',
        'TOMA' => 'Toma de inventario',
        'DEVOLUCION_COMPRA' => 'Devolución al proveedor',
        'REPOSICION' => 'Reposición del proveedor',
    ];

    protected $fillable = [
        'producto_id', 'usuario_id', 'tipo', 'origen',
        'venta_id', 'compra_id', 'devolucion_compra_id', 'toma_id',
        'cantidad', 'stock_anterior', 'stock_resultante', 'costo_unitario', 'motivo', 'fecha',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'stock_anterior' => 'decimal:3',
            'stock_resultante' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function devolucionCompra(): BelongsTo
    {
        return $this->belongsTo(DevolucionCompra::class, 'devolucion_compra_id');
    }

    public function toma(): BelongsTo
    {
        return $this->belongsTo(TomaInventario::class, 'toma_id');
    }

    public function getOrigenVisibleAttribute(): string
    {
        return self::ORIGENES[$this->origen] ?? $this->origen;
    }

    /** La cantidad con su signo: + entra, − sale. */
    public function getCantidadConSignoAttribute(): float
    {
        return $this->tipo === 'SALIDA' ? -(float) $this->cantidad : (float) $this->cantidad;
    }
}
