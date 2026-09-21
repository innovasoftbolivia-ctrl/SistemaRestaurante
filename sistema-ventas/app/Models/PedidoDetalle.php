<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un plato pedido, con su nota y su estado en la cocina.
 *
 * El mostrador manda una línea por plato, con su nota. Si dos líneas son del
 * mismo producto, cada una conserva su nota y su estado en la cocina; el cobro
 * las agrupa (ver `Pedidos::cobrar`).
 */
class PedidoDetalle extends Model
{
    protected $table = 'pedido_detalle';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const PENDIENTE = 'PENDIENTE';

    public const EN_PREPARACION = 'EN_PREPARACION';

    public const LISTO = 'LISTO';

    public const ENTREGADO = 'ENTREGADO';

    public const CANCELADO = 'CANCELADO';

    /**
     * A dónde puede ir cada estado. El camino normal es de arriba abajo, y
     * cancelar solo se admite mientras el plato no esté hecho: lo que ya salió
     * de la cocina ya se sirvió, y eso se cobra: si el error es del negocio,
     * se cobra la cuenta y se anula la venta entera.
     *
     * No se admite volver atrás: si la cocina se adelantó, se cancela y se
     * pide de nuevo, y así queda el rastro de lo que pasó.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        self::PENDIENTE => [self::EN_PREPARACION, self::LISTO, self::CANCELADO],
        self::EN_PREPARACION => [self::LISTO, self::CANCELADO],
        self::LISTO => [self::ENTREGADO],
        self::ENTREGADO => [],
        self::CANCELADO => [],
    ];

    /**
     * Lo que la cocina puede hacer con un plato: avanzarlo. Cancelar no está:
     * es dejarlo sin cobrar, y eso lo decide la caja (ver
     * `Pedidos::actualizarEstadoLinea`).
     */
    public const PASOS_DE_COCINA = [self::EN_PREPARACION, self::LISTO, self::ENTREGADO];

    /** Lo que la cocina todavía tiene que hacer. */
    public const EN_COCINA = [self::PENDIENTE, self::EN_PREPARACION];

    protected $fillable = [
        'pedido_id', 'producto_id', 'descripcion', 'cantidad', 'precio_unitario',
        'nota', 'pasa_por_cocina', 'estado_cocina', 'usuario_id', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
            'pasa_por_cocina' => 'boolean',
            // Cuándo salió en la comanda impresa, y cuándo salió el aviso de
            // que se canceló (ver `App\Services\Comandas`).
            'comandado_en' => 'datetime',
            'cancelacion_comandada_en' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function scopeEnCocina(Builder $query): Builder
    {
        return $query->whereIn('estado_cocina', self::EN_COCINA);
    }

    /**
     * Lo que prepara la cocina: sin las bebidas y demás de las categorías que
     * no pasan por ella (`categorias.pasa_por_cocina`, copiado a la línea al
     * pedirla). Es lo único que va a su pantalla y a la comanda.
     */
    public function scopeParaLaCocina(Builder $query): Builder
    {
        return $query->where('pasa_por_cocina', true);
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado_cocina] ?? [], true);
    }

    /** El siguiente paso del camino normal, para el botón de la cocina. */
    public function getSiguienteEstadoAttribute(): ?string
    {
        return match ($this->estado_cocina) {
            self::PENDIENTE => self::EN_PREPARACION,
            self::EN_PREPARACION => self::LISTO,
            self::LISTO => self::ENTREGADO,
            default => null,
        };
    }

    /**
     * Estado en palabras, para la pantalla.
     *
     * Lo que no pasa por la cocina no está «pendiente» de nada: mientras el
     * pedido sigue abierto se lee «Sin cocina», y al cobrar pasa a ENTREGADO
     * (ver `Pedidos::cobrar`).
     */
    public function getEstadoVisibleAttribute(): string
    {
        if ($this->pasa_por_cocina === false && $this->estado_cocina === self::PENDIENTE) {
            return 'Sin cocina';
        }

        return match ($this->estado_cocina) {
            self::PENDIENTE => 'Pendiente',
            self::EN_PREPARACION => 'En preparación',
            self::LISTO => 'Listo',
            self::ENTREGADO => 'Entregado',
            self::CANCELADO => 'Cancelado',
            default => $this->estado_cocina,
        };
    }
}
