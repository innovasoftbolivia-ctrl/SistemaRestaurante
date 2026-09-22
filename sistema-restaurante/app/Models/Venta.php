<?php

namespace App\Models;

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

    public const ESTADOS = ['COMPLETADA', 'ANULADA'];

    protected $fillable = [
        'cliente_id', 'usuario_id', 'sesion_caja_id', 'pedido_id', 'fecha',
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
     * Las notas del pedido («sin hielo», «bien cocido»), por producto: la
     * línea de la venta no las guarda, las guarda la del pedido. Sin esto la
     * nota de lo que no pasa por la cocina —la gaseosa— no salía en ningún
     * lado, y es justo lo que se entrega en el mostrador con el ticket.
     *
     * @return array<int, string>
     */
    public function notasPorProducto(): array
    {
        if (! $this->pedido_id) {
            return [];
        }

        return PedidoDetalle::where('pedido_id', $this->pedido_id)
            ->where('estado_cocina', '<>', PedidoDetalle::CANCELADO)
            ->whereNotNull('nota')
            ->where('nota', '<>', '')
            ->orderBy('id')
            ->get(['producto_id', 'nota'])
            ->groupBy('producto_id')
            ->map(fn ($lineas) => $lineas->pluck('nota')->unique()->implode(' · '))
            ->all();
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

    /** El documento válido hoy; los sustituidos y anulados son historial. */
    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class, 'venta_id')->where('estado', 'EMITIDO');
    }

    /**
     * El pedido que cobró esta venta: toda venta del mostrador trae uno, y lo
     * sigue diciendo aunque se anule. Una venta vieja, de antes de los
     * pedidos, no tiene ninguno.
     *
     * La clave vive aquí (`ventas.pedido_id`) porque un pedido puede tener
     * varias ventas —la anulada y la que lo volvió a cobrar—, pero una sola
     * vigente: eso lo garantiza el índice único de `pedido_cobrado_uk`.
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function scopeCompletadas(Builder $query): Builder
    {
        return $query->where('estado', 'COMPLETADA');
    }

    /**
     * Anular es para el error del momento: solo mientras el turno de caja de
     * la venta sigue abierto. Si el turno ya cerró, ese dinero ya se contó en
     * su arqueo, y anular cambiaría un cierre ya firmado. Pasado ese punto, la
     * corrección se hace fuera del sistema, con el arqueo del día siguiente.
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

    public function getVueltoAttribute(): float
    {
        return (float) $this->pagos->sum('vuelto');
    }
}
