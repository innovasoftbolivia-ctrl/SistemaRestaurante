<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
        'impuesto_incluido', 'descuento_precio_final',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'anulada_en' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'impuesto_incluido' => 'boolean',
            'descuento_precio_final' => 'decimal:2',
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

    /**
     * Lo que se le debe al cliente al anular una venta que se cobró, en todo o
     * en parte, por QR, tarjeta o transferencia: el efectivo se devuelve en el
     * mostrador, pero eso sigue en el banco.
     */
    public function getReintegroPorAnulacionAttribute(): float
    {
        if ($this->estado !== 'ANULADA') {
            return 0.0;
        }

        return self::fueraDelCajon($this->id);
    }

    /** Lo cobrado a esta venta por medios que no pasan por el cajón. */
    public static function fueraDelCajon(int $ventaId): float
    {
        return round((float) DB::table('venta_pagos as p')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->where('p.venta_id', $ventaId)
            ->where('m.afecta_caja', 0)
            ->sum('p.monto'), 2);
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
    /**
     * El descuento como lo vio el cliente: con el impuesto incluido, sobre el
     * precio final; si no, sobre la base.
     */
    public function getDescuentoVisibleAttribute(): float
    {
        return $this->impuesto_incluido ? (float) $this->descuento_precio_final : (float) $this->descuento;
    }

    /** Lo cobrado por los productos antes del descuento, con el impuesto incluido. */
    public function getTotalAntesDelDescuentoAttribute(): float
    {
        return round((float) $this->total + $this->descuento_visible, 2);
    }

    /** Días después de la venta en que todavía se acepta una devolución. */
    public static function diasParaDevolver(): int
    {
        return max(0, (int) Config::get('dias_max_devolucion', '7'));
    }

    /** Del día de la venta a hoy, en días de calendario. */
    public function dentroDelPlazoDeDevolucion(): bool
    {
        return $this->fecha->copy()->startOfDay()->diffInDays(now()->startOfDay()) <= self::diasParaDevolver();
    }

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
