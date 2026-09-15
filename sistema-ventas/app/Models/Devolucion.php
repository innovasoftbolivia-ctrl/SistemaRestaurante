<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolución de mercadería de una venta ya cobrada.
 *
 * `total` no se escribe a mano: lo recalcula el trigger de
 * `devolucion_detalle` sumando `total_linea`, es decir CON impuesto —lo que el
 * cliente pagó y lo que sale del cajón—, igual que hace con el estado de la
 * venta y con el stock.
 *
 * Se ata a la sesión de caja de quien la registra, no a la de la venta
 * original: el dinero sale del cajón de hoy, y así lo descuenta el arqueo.
 */
class Devolucion extends Model
{
    protected $table = 'devoluciones';

    public $timestamps = false;

    public const TIPOS = ['TOTAL', 'PARCIAL'];

    protected $fillable = [
        'venta_id', 'usuario_id', 'autorizado_por', 'sesion_caja_id',
        'fecha', 'tipo', 'motivo', 'reembolso', 'efectivo',
    ];

    public const EFECTIVO = 'EFECTIVO';

    public const MISMO_MEDIO = 'MISMO_MEDIO';

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'total' => 'decimal:2',
            'efectivo' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizado_por');
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(DevolucionDetalle::class, 'devolucion_id');
    }

    /**
     * Base imponible de lo devuelto, sin impuesto. `total` ya viene con él:
     * es el dinero que sale del cajón.
     */
    public function getBaseAttribute(): float
    {
        return round((float) $this->detalle->sum('importe'), 2);
    }

    /** Impuesto que se le reintegra al cliente. */
    public function getImpuestoDevueltoAttribute(): float
    {
        return round((float) $this->detalle->sum('impuesto_linea'), 2);
    }
}
