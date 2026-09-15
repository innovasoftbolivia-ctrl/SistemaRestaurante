<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La operación comercial. El documento entregado al cliente vive aparte, en
 * `comprobantes`.
 *
 * Los importes no se escriben a mano: `subtotal` e `impuesto` los calcula
 * `sp_recalcular_venta` a partir del detalle, y `total` es columna generada.
 * Una venta nunca se borra (hay un trigger que lo impide): se anula.
 */
class Venta extends Model
{
    protected $table = 'ventas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    public const ESTADOS = ['COMPLETADA', 'ANULADA', 'DEVUELTA_PARCIAL', 'DEVUELTA'];

    protected $fillable = [
        'cliente_id', 'usuario_id', 'sesion_caja_id', 'fecha',
        'descuento', 'estado', 'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'anulada_en' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
            'total_devuelto' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'anulada_por');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(VentaDetalle::class, 'venta_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(VentaPago::class, 'venta_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'venta_id');
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'venta_id');
    }

    /** El documento válido hoy; los sustituidos y anulados son historial. */
    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class, 'venta_id')->where('estado', 'EMITIDO');
    }

    public function scopeCompletadas(Builder $query): Builder
    {
        return $query->where('estado', 'COMPLETADA');
    }

    /**
     * Anular es para el error del momento: solo mientras el turno de caja de
     * la venta sigue abierto. Si el turno ya cerró, ese dinero ya se contó en
     * su arqueo; anular cambiaría los reportes de ese día y la plata devuelta
     * saldría de un cajón que no la registra. Para eso está la devolución,
     * que queda en el turno de hoy.
     */
    public function puedeAnularse(): bool
    {
        return $this->estado === 'COMPLETADA' && $this->turnoAbierto();
    }

    public function turnoAbierto(): bool
    {
        return $this->sesionCaja?->estado === 'ABIERTA';
    }

    /**
     * Una venta anulada ya devolvió su stock y su dinero; una totalmente
     * devuelta no tiene nada más que devolver.
     */
    public function admiteDevolucion(): bool
    {
        return in_array($this->estado, ['COMPLETADA', 'DEVUELTA_PARCIAL'], true);
    }

    /** Lo que aún se le puede devolver al cliente. */
    public function getTotalDevolvibleAttribute(): float
    {
        return round((float) $this->total - (float) $this->total_devuelto, 2);
    }

    public function getVueltoAttribute(): float
    {
        return (float) $this->pagos->sum('vuelto');
    }
}
