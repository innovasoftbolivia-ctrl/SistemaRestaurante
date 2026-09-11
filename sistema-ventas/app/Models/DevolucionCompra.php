<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * `espera` es en qué se quedó con el proveedor, y son tres cosas distintas:
 *
 *   REPUESTO      se lo cambió en el momento. Salió lo fallado y entró lo
 *                 bueno en el mismo documento, así que el stock terminó como
 *                 estaba — pero queda escrito que hubo un problema.
 *   PENDIENTE     se llevó la mercadería y traerá el reemplazo. El stock bajó
 *                 hoy y volverá a subir cuando llegue. Hasta entonces el
 *                 proveedor DEBE mercadería, y eso hay que poder verlo.
 *   NOTA_CREDITO  no repone nada: queda a cuenta.
 *
 * Era un booleano y no alcanzaba: sin el estado de en medio, «me lo trae la
 * semana que viene» era indistinguible de «no me trae nada», y el reemplazo
 * terminaba cargado como un ingreso suelto sin hilo con lo que lo originó.
 */
class DevolucionCompra extends Model
{
    protected $table = 'devoluciones_compra';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    /** Por qué se devuelve. Es lo que permite contar «cuánto por vencimiento». */
    public const MOTIVOS = ['DEFECTO', 'VENCIMIENTO', 'ERROR', 'OTRO'];

    /** En qué se quedó con el proveedor. */
    public const ESPERAS = ['REPUESTO', 'PENDIENTE', 'NOTA_CREDITO'];

    protected $fillable = [
        'compra_id', 'usuario_id', 'fecha', 'motivo',
        'espera', 'documento_externo', 'observacion',
    ];

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

    public function getEtiquetaEsperaAttribute(): string
    {
        return match ($this->espera) {
            'REPUESTO' => 'Lo repuso en el momento',
            'PENDIENTE' => 'Lo va a reponer',
            default => 'Nota de crédito',
        };
    }

    /** Cuánta mercadería debe todavía el proveedor por esta devolución. */
    public function getPendienteReposicionAttribute(): float
    {
        if ($this->espera !== 'PENDIENTE') {
            return 0.0;
        }

        return round(
            (float) $this->detalle->sum(fn (DevolucionCompraDetalle $l) => $l->pendiente_reposicion),
            3,
        );
    }

    /**
     * Las que todavía esperan mercadería.
     *
     * En SQL y no filtrando en PHP porque es la pregunta del almacén —«¿qué me
     * deben?»— y tiene que poder hacerse sobre la tabla entera.
     */
    public function scopeEsperandoReposicion(Builder $query): Builder
    {
        return $query->where('espera', 'PENDIENTE')
            ->whereHas('detalle', fn ($q) => $q->whereColumn('cantidad_repuesta', '<', 'cantidad'));
    }
}
