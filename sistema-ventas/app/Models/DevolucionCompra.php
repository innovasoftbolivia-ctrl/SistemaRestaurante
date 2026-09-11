<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mercadería que se le devuelve al proveedor, colgada de la compra por la que
 * entró.
 *
 * No es lo mismo que `devoluciones`, que es del cliente hacia la tienda: una
 * suma al stock y la otra lo resta, una la firma el cajero y la otra el
 * almacenero. Mezclarlas en la misma tabla habría hecho imposible después
 * contar cuánto se le devolvió a cada proveedor.
 *
 * `con_reposicion` es lo que convierte una devolución en un cambio: el
 * proveedor se lleva lo fallado y deja lo bueno, así que el stock termina como
 * estaba y los dos movimientos quedan en el mismo documento.
 */
class DevolucionCompra extends Model
{
    protected $table = 'devoluciones_compra';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    /** Por qué se devuelve. Es lo que permite contar «cuánto por vencimiento». */
    public const MOTIVOS = ['DEFECTO', 'VENCIMIENTO', 'ERROR', 'OTRO'];

    protected $fillable = [
        'compra_id', 'usuario_id', 'fecha', 'motivo',
        'con_reposicion', 'documento_externo', 'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'con_reposicion' => 'boolean',
        ];
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

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'devolucion_compra_id');
    }

    public function getTotalAttribute(): float
    {
        return round((float) $this->detalle->sum('importe'), 2);
    }

    public function getEtiquetaMotivoAttribute(): string
    {
        return match ($this->motivo) {
            'DEFECTO' => 'Vino fallado',
            'VENCIMIENTO' => 'Vencido o por vencer',
            'ERROR' => 'No es lo que se pidió',
            default => 'Otro motivo',
        };
    }
}
