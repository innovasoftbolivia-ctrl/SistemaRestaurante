<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del carrito, con copia histórica del nombre, de la unidad y del
 * precio: si mañana el producto cambia de precio —o de unidad—, la venta ya
 * emitida no se altera, y el comprobante reimpreso sigue diciendo lo mismo que
 * el que se entregó en mano.
 *
 * `importe`, `impuesto_linea` y `total_linea` son columnas generadas, y el
 * régimen de impuesto lo copia del producto un trigger BEFORE INSERT.
 * Al insertar la línea, otro trigger descuenta el stock y escribe el kardex.
 */
class VentaDetalle extends Model
{
    protected $table = 'venta_detalle';

    public $timestamps = false;

    // `afecto_impuesto` y `tasa_impuesto` los pone normalmente un trigger de la
    // base, pero tienen que ser asignables para la vía sin triggers, donde los
    // calcula PHP (ver config/ventas.php). Con el trigger activo llegan en 0 y
    // él los pisa, así que estar en esta lista no cambia nada en esa vía.
    protected $fillable = [
        'venta_id', 'producto_id', 'descripcion', 'unidad',
        'cantidad', 'precio_unitario', 'descuento', 'costo_unitario',
        'afecto_impuesto', 'tasa_impuesto', 'impuesto_incluido',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'descuento' => 'decimal:2',
            'importe' => 'decimal:2',
            'tasa_impuesto' => 'decimal:4',
            'impuesto_linea' => 'decimal:2',
            'total_linea' => 'decimal:2',
            'cantidad_devuelta' => 'decimal:3',
            'afecto_impuesto' => 'boolean',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /**
     * «2.5 KG», que es como tiene que leerse en el papel.
     *
     * Las líneas vendidas antes de que existiera la columna no tienen copia, y
     * ahí se cae a la unidad que el producto tiene hoy: es peor que la copia,
     * pero mucho mejor que imprimir un número pelado.
     */
    public function getCantidadConUnidadAttribute(): string
    {
        $unidad = $this->unidad ?: $this->producto?->unidadMedida?->codigo;

        return trim(Config::cantidad($this->cantidad).' '.$unidad);
    }

    /** Cuánto de esta línea queda todavía sin devolver. */
    public function getPendienteDevolucionAttribute(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 3);
    }
}
