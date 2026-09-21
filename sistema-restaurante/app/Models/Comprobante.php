<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El documento entregado al cliente. Guarda una FOTO de los datos al emitir:
 * los del cliente y los del negocio (`emisor_*`). Si después cambian la razón
 * social, el NIT o la dirección, el documento ya emitido se sigue imprimiendo
 * como se entregó. Nada se borra; se anula o se sustituye.
 */
class Comprobante extends Model
{
    protected $table = 'comprobantes';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'venta_id', 'serie_id', 'numero', 'numero_completo', 'fecha_emision',
        'emisor_nombre', 'emisor_documento', 'emisor_direccion', 'emisor_telefono',
        'cliente_id', 'tipo_persona', 'cliente_nombre', 'cliente_tipo_documento',
        'cliente_documento', 'cliente_direccion', 'representante_legal',
        'subtotal', 'descuento', 'impuesto', 'moneda',
        'estado', 'sustituye_a', 'motivo_emision', 'emitido_por', 'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'anulado_en' => 'datetime',
            'sustituido_en' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(SerieComprobante::class, 'serie_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function emitidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'emitido_por');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', 'EMITIDO');
    }

    /** El tipo sale de la serie: es la única fuente de verdad. */
    public function getNombreTipoAttribute(): string
    {
        return $this->serie?->tipo?->nombre ?? 'Comprobante';
    }

    public function getEsFacturaAttribute(): bool
    {
        return $this->serie?->tipo?->codigo === 'FAC';
    }
}
