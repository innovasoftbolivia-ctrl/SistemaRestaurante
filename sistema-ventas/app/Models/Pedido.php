<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un pedido del mostrador: para comer aquí o para llevar, con su número de la
 * jornada, que es lo que el cliente tiene en el ticket y lo que se canta al
 * entregar.
 *
 * Nace y se cobra en el mismo acto (`Pedidos::venderEnMostrador`). Solo queda
 * ABIERTO —con el cobro anulado; en pantalla, «Volver a cobrar»— el que se
 * reabrió al anular su venta, para cobrarlo de nuevo con el mismo número, o
 * cancelarlo.
 *
 * No guarda importes: el total es la suma de su detalle mientras está abierto,
 * y en cuanto se cobra el dinero vive en la `venta` —con su comprobante, sus
 * pagos y su efecto en el arqueo—. Así no hay dos cifras que puedan
 * contradecirse. Un pedido no se borra nunca (hay un trigger que lo impide):
 * se cancela con su motivo.
 *
 * Tampoco guarda la venta ni el cliente: la venta apunta a su pedido
 * (`ventas.pedido_id`), porque un pedido puede tener varias —la anulada y la
 * que lo volvió a cobrar—, y el cliente es de la venta. Al pedido le basta un
 * nombre para llamarlo.
 *
 * «CERRADO ⇔ tiene una venta COMPLETADA» ya no cabe en un CHECK, porque son
 * dos tablas: lo garantiza `App\Services\Pedidos`, que cierra el pedido en la
 * misma transacción en que registra su venta (`cobrar`) y lo reabre en la
 * misma en que se anula (`reabrirTrasAnular`).
 */
class Pedido extends Model
{
    protected $table = 'pedidos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    /** Para comer en el local, sentado donde quiera: el local no numera las mesas. */
    public const LOCAL = 'LOCAL';

    public const LLEVAR = 'LLEVAR';

    public const ABIERTO = 'ABIERTO';

    public const CERRADO = 'CERRADO';

    public const CANCELADO = 'CANCELADO';

    /** Lo que se elige en el mostrador: comer aquí o para llevar. */
    public const TIPOS = [self::LOCAL, self::LLEVAR];

    protected $fillable = [
        'tipo', 'numero_dia', 'jornada', 'usuario_id',
        'nombre_cliente', 'estado', 'observacion',
        'motivo_cancelacion', 'fecha_apertura', 'fecha_cierre', 'cerrado_por',
    ];

    protected function casts(): array
    {
        return [
            'numero_dia' => 'integer',
            'jornada' => 'date',
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    /** Quien lo abrió: el cajero que tomó el pedido. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrado_por');
    }

    /** Todas las ventas que lo cobraron: las anuladas y, si está cobrado, la vigente. */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'pedido_id');
    }

    /** La venta vigente: la que lo tiene cobrado. Un pedido abierto o cancelado no tiene. */
    public function venta(): HasOne
    {
        return $this->hasOne(Venta::class, 'pedido_id')->where('estado', 'COMPLETADA');
    }

    /**
     * La última venta que lo cobró, vigente o anulada: de ahí sale a nombre de
     * quién va el pedido cuando no se dio un nombre para llamarlo.
     */
    public function ultimaVenta(): HasOne
    {
        return $this->hasOne(Venta::class, 'pedido_id')->latestOfMany();
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id');
    }

    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->where('estado', self::ABIERTO);
    }

    public function estaAbierto(): bool
    {
        return $this->estado === self::ABIERTO;
    }

    public function esParaLlevar(): bool
    {
        return $this->tipo === self::LLEVAR;
    }

    /**
     * Cuántos platos ya pasaron por la cocina: empezados, listos o servidos.
     *
     * Es lo que separa cancelar una cuenta de anular una venta. Lo pendiente
     * no se perdió; lo que la cocina tocó ya costó y casi siempre se comió, y
     * cancelarlo es dejarlo sin cobrar (ver `Pedidos::cancelar`).
     */
    public function platosEmpezados(): int
    {
        return $this->detalle()
            ->whereNotIn('estado_cocina', [PedidoDetalle::PENDIENTE, PedidoDetalle::CANCELADO])
            ->count();
    }

    /**
     * «Comer aquí» o «Para llevar»: lo que dicen el ticket, la comanda y la
     * cocina junto al número.
     *
     * A diferencia de `etiqueta`, no le agrega el nombre de quien lo pidió: en
     * el ticket va aparte, y repetirlo junto al número solo alarga lo único
     * que tiene que leerse de un vistazo.
     */
    public function getDestinoAttribute(): string
    {
        return $this->esParaLlevar() ? 'Para llevar' : 'Comer aquí';
    }

    /**
     * El número que se canta en la barra: «Pedido 7».
     *
     * Es `numero_dia` y no el `id` porque el `id` es global y creciente: a los
     * pocos meses de servicio va por «4812», y eso no se grita. El `id` sigue
     * siendo la clave y lo que va en las URLs; esto es lo que lee una persona.
     *
     * El número vale dentro de su `jornada`, no del día de calendario: el
     * local cierra pasada la medianoche, y el pedido de la 01:30 sigue la
     * numeración de la noche (ver `Config::jornadaDe`).
     */
    public function getNumeroVisibleAttribute(): string
    {
        return 'Pedido '.$this->numero_dia;
    }

    /** «Pedido 7 · Comer aquí» o «Pedido 7 · Para llevar · Ana», para nombrarlo en una lista. */
    public function getEtiquetaAttribute(): string
    {
        return $this->numero_visible.' · '.$this->destino.($this->quien ? ' · '.$this->quien : '');
    }

    /** A nombre de quién va, si se sabe: el nombre que se dio al pedir, o el cliente de su venta. */
    public function getQuienAttribute(): ?string
    {
        return $this->nombre_cliente ?: $this->ultimaVenta?->cliente?->nombre;
    }
}
