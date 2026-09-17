<?php

namespace App\Models;

use App\Support\Menu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kardex: cada entrada, salida o ajuste de stock, con su responsable.
 * Los registros no se editan ni se borran; una corrección es otro movimiento.
 */
class MovimientoInventario extends Model
{
    /** Los orígenes que salen del mostrador: quién vendió es de cada cajero. */
    public const DEL_MOSTRADOR = ['VENTA', 'DEVOLUCION', 'ANULACION'];

    /**
     * Quién hizo el movimiento, tal como lo puede ver quien mira. Las ventas,
     * devoluciones y anulaciones son movimientos de cada cajero: solo el
     * administrador ve de quién. El almacén ve que salió por el mostrador.
     */
    public function getResponsableVisibleAttribute(): string
    {
        if (in_array($this->origen, self::DEL_MOSTRADOR, true) && ! Menu::puede('reportes.ver')) {
            return 'Mostrador';
        }

        return $this->usuario?->usuario ?? 'sistema';
    }

    protected $table = 'movimientos_inventario';

    public $timestamps = false;

    public const TIPOS = ['ENTRADA', 'SALIDA', 'AJUSTE'];

    public const ORIGENES = ['VENTA', 'COMPRA', 'DEVOLUCION', 'DEVOLUCION_COMPRA', 'ANULACION', 'AJUSTE', 'INICIAL'];

    protected $fillable = [
        'producto_id', 'usuario_id', 'tipo', 'origen',
        'venta_id', 'devolucion_id', 'proveedor_id', 'compra_id', 'devolucion_compra_id', 'documento_externo',
        'cantidad', 'stock_anterior', 'stock_resultante',
        'costo_unitario', 'motivo', 'fecha',
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

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function devolucionCompra(): BelongsTo
    {
        return $this->belongsTo(DevolucionCompra::class, 'devolucion_compra_id');
    }

    /** Cuánto sumó o restó al stock, con signo. */
    public function getVariacionAttribute(): float
    {
        return round((float) $this->stock_resultante - (float) $this->stock_anterior, 3);
    }

    public function getEtiquetaOrigenAttribute(): string
    {
        return match ($this->origen) {
            'VENTA' => 'Venta',
            'COMPRA' => 'Ingreso de mercadería',
            'DEVOLUCION_COMPRA' => 'Devolución al proveedor',
            'DEVOLUCION' => 'Devolución',
            'ANULACION' => 'Anulación de venta',
            'AJUSTE' => 'Ajuste de inventario',
            'INICIAL' => 'Carga inicial',
            default => $this->origen,
        };
    }
}
