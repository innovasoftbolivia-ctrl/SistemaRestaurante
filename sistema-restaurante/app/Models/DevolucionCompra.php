<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo que se le devuelve al proveedor contra una compra: botellas dañadas, un
 * producto equivocado. Termina de tres maneras (`espera`): REPUESTO, PENDIENTE
 * o NOTA_CREDITO (ver App\Services\DevolucionesCompra).
 */
class DevolucionCompra extends Model
{
    protected $table = 'devoluciones_compra';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    public const MOTIVOS = [
        'DEFECTO' => 'Llegó dañado o con defecto',
        'VENCIMIENTO' => 'Vencido o por vencer',
        'ERROR' => 'Error del pedido (producto o cantidad)',
        'OTRO' => 'Otro motivo',
    ];

    public const ESPERAS = [
        'REPUESTO' => 'Lo cambió en el momento',
        'PENDIENTE' => 'Traerá el reemplazo',
        'NOTA_CREDITO' => 'No repone: nota de crédito',
    ];

    protected $fillable = ['compra_id', 'usuario_id', 'fecha', 'motivo', 'espera', 'documento_externo', 'observacion'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime'];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(DevolucionCompraDetalle::class, 'devolucion_compra_id');
    }

    /** ¿El proveedor todavía debe mercadería de esta devolución? */
    public function getDebeReponerAttribute(): bool
    {
        return $this->espera === 'PENDIENTE'
            && $this->detalle->contains(fn (DevolucionCompraDetalle $d) => (float) $d->cantidad_repuesta < (float) $d->cantidad);
    }

    public function getTotalAttribute(): float
    {
        return round((float) $this->detalle->sum('importe'), 2);
    }
}
