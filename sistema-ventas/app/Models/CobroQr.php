<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro por código QR con el importe ya puesto.
 *
 * Vive por su cuenta mientras se espera al cliente: `venta_id` queda en NULL
 * hasta que el pago se confirma. Si se registrara la venta antes de cobrar, un
 * cliente que se arrepiente dejaría una venta y un comprobante emitido por una
 * cuenta que nadie pagó.
 */
class CobroQr extends Model
{
    protected $table = 'cobros_qr';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const PENDIENTE = 'PENDIENTE';

    public const PAGADO = 'PAGADO';

    public const EXPIRADO = 'EXPIRADO';

    public const ANULADO = 'ANULADO';

    public const ESTADOS = [self::PENDIENTE, self::PAGADO, self::EXPIRADO, self::ANULADO];

    protected $fillable = [
        'sesion_caja_id', 'usuario_id', 'venta_id',
        'monto', 'moneda', 'glosa',
        'pasarela', 'id_externo', 'payload',
        'estado', 'expira_en', 'pagado_en',
        'confirmado_por', 'confirmado_por_id', 'referencia_bancaria', 'respuesta',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'expira_en' => 'datetime',
            'pagado_en' => 'datetime',
        ];
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'confirmado_por_id');
    }

    // ------------------------------------------------------------- derivados

    public function estaPagado(): bool
    {
        return $this->estado === self::PAGADO;
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    /** Se pasó del plazo sin que nadie lo pagara. */
    public function venció(): bool
    {
        return $this->expira_en !== null && $this->expira_en->isPast();
    }

    /** Un cobro ya usado en una venta no se puede volver a usar en otra. */
    public function estaLibre(): bool
    {
        return $this->estaPagado() && $this->venta_id === null;
    }

    public function getEtiquetaEstadoAttribute(): string
    {
        return match ($this->estado) {
            self::PENDIENTE => 'Esperando el pago',
            self::PAGADO => 'Pagado',
            self::EXPIRADO => 'Vencido',
            self::ANULADO => 'Cancelado',
            default => $this->estado,
        };
    }
}
