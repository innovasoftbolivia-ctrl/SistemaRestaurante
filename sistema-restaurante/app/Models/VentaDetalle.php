<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la venta, con copia histórica del nombre y del precio: si
 * mañana el producto cambia de precio, la venta ya emitida no se altera, y el
 * comprobante reimpreso sigue diciendo lo mismo que el que se entregó en mano.
 *
 * `importe`, `impuesto_linea` y `total_linea` son columnas generadas, y el
 * régimen de impuesto lo copia del producto un trigger BEFORE INSERT.
 */
class VentaDetalle extends Model
{
    protected $table = 'venta_detalle';

    public $timestamps = false;

    // `afecto_impuesto` y `tasa_impuesto` los pone normalmente un trigger de la
    // base, pero tienen que ser asignables para la vía sin triggers, donde los
    // calcula PHP (ver config/restaurante.php). Con el trigger activo llegan en 0 y
    // él los pisa, así que estar en esta lista no cambia nada en esa vía.
    protected $fillable = [
        'venta_id', 'producto_id', 'descripcion',
        'cantidad', 'precio_unitario', 'costo_unitario',
        'afecto_impuesto', 'tasa_impuesto', 'impuesto_incluido',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'costo_unitario' => 'decimal:4',

            'importe' => 'decimal:2',
            'tasa_impuesto' => 'decimal:4',
            'impuesto_linea' => 'decimal:2',
            'total_linea' => 'decimal:2',
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

    /** «2» o «2.5», que es como tiene que leerse en el papel. */
    public function getCantidadVisibleAttribute(): string
    {
        return Config::cantidad($this->cantidad);
    }
}
