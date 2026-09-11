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
        'lote_id', 'cantidad', 'cantidad_repuesta', 'costo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_repuesta' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    /**
     * Cuánto de esta línea falta que el proveedor reponga.
     *
     * El acumulado vive aquí y no se calcula sumando ingresos por el mismo
     * motivo que en las otras dos tablas del sistema que llevan una cuenta
     * parecida: una restricción no puede consultar otra tabla, y sin el tope en
     * la propia línea nada impediría que el proveedor «repusiera» más de lo que
     * se le devolvió.
     */
    public function getPendienteReposicionAttribute(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_repuesta, 3);
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
