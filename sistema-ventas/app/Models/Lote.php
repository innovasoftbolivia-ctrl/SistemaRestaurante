<?php

namespace App\Models;

use App\Services\Lotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una tanda de un producto con su fecha de vencimiento.
 *
 * `productos.stock_actual` sigue siendo el saldo y la única cifra que mira el
 * mostrador. Los lotes lo parten por fecha para poder responder qué se vence
 * pronto, y su suma tiene que dar ese mismo saldo — de eso se encarga
 * {@see Lotes}, que es el único sitio que los escribe.
 *
 * Solo existen para los productos con `controla_vencimiento`: el detergente no
 * vence y no tiene por qué arrastrar una fecha.
 */
class Lote extends Model
{
    protected $table = 'lotes';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'producto_id', 'codigo', 'fecha_vencimiento',
        'cantidad_inicial', 'cantidad_actual', 'compra_detalle_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'cantidad_inicial' => 'decimal:3',
            'cantidad_actual' => 'decimal:3',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    // ------------------------------------------------------------- consultas

    /** Los que todavía tienen unidades. Un lote agotado ya no es stock. */
    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->where('cantidad_actual', '>', 0);
    }

    /**
     * El orden en que se despacha: primero lo que vence antes.
     *
     * Los lotes sin fecha van al final. No es que sean los más nuevos: es que
     * no se sabe cuándo vencen, y algo que no se sabe no puede reclamar
     * prioridad sobre algo que sí tiene fecha y está por vencerse.
     */
    public function scopeEnOrdenDeSalida(Builder $query): Builder
    {
        // Primero lo vigente que vence antes; después lo que no tiene fecha; y al
        // final lo ya vencido. Lo vencido está retirado del estante: si la venta
        // lo descontara primero, la alerta de vencidos se apagaría sola y el
        // sistema diría que esa mercadería se vendió.
        return $query
            ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 WHEN fecha_vencimiento < CURDATE() THEN 2 ELSE 0 END')
            ->orderBy('fecha_vencimiento')
            ->orderBy('id');
    }

    /** Ya vencidos: no deberían venderse, y el sistema tiene que decirlo. */
    public function scopeVencidos(Builder $query): Builder
    {
        return $query->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<', now());
    }

    /** Los que vencen dentro de los próximos `$dias` días. */
    public function scopePorVencer(Builder $query, int $dias): Builder
    {
        return $query->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '>=', now())
            ->whereDate('fecha_vencimiento', '<=', now()->addDays($dias));
    }

    // ------------------------------------------------------------- derivados

    /** Cuántos días faltan. Negativo si ya venció. Null si no tiene fecha. */
    public function getDiasParaVencerAttribute(): ?int
    {
        if (! $this->fecha_vencimiento) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->fecha_vencimiento->startOfDay(), false);
    }

    public function getVencidoAttribute(): bool
    {
        return $this->dias_para_vencer !== null && $this->dias_para_vencer < 0;
    }

    /** Cuánto de este lote ya salió. */
    public function getConsumidoAttribute(): float
    {
        return round((float) $this->cantidad_inicial - (float) $this->cantidad_actual, 3);
    }
}
