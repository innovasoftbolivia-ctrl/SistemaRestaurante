<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un ítem del menú: un plato, una bebida o un postre de la carta.
 *
 * De cara al usuario el módulo se llama «Menú»; la tabla, el modelo y la
 * columna `producto_id` siguen diciéndose `producto` porque renombrarlos
 * tocaría el esquema, los triggers y las vistas de una base ya en producción.
 *
 * Sobre los precios: un plato tiene uno solo, `precio_venta`, el que pone el
 * usuario. Depende de cómo trabaja el negocio (Configuración → precios con
 * impuesto incluido): si el precio ya trae el impuesto, es lo que paga el
 * cliente; si no, es la base y el cliente paga `precio_venta * (1 + tasa)`.
 *
 * No hay precio de compra: en un restaurante el producto se hace ahí, no se
 * revende, y no había costo que registrar ni margen que calcular.
 *
 * Tampoco lleva unidad de medida: en un restaurante todo se despacha por
 * porción, y una carta con «KG» o «LT» al lado de cada plato solo obligaba a
 * elegir algo que nadie miraba.
 */
class Producto extends Model
{
    protected $table = 'productos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'categoria_id',
        'codigo', 'nombre', 'descripcion',
        'precio_venta', 'afecto_impuesto',
        'imagen', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'afecto_impuesto' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    // ------------------------------------------------------------- consultas

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    /** Busca un plato por nombre o por su código interno (RNF2: teclear + Enter). */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('codigo', 'like', "%{$texto}%");
        });
    }

    // ------------------------------------------------------------- derivados

    /** Precio que ve el cliente: con el impuesto, si el producto está afecto. */
    public function getPrecioEstanteAttribute(): float
    {
        $precio = (float) $this->precio_venta;

        if (Config::preciosIncluyenImpuesto() || ! $this->afecto_impuesto) {
            return round($precio, 2);
        }

        return round($precio + Config::impuestoDe($precio), 2);
    }

    /** Precio de venta sin impuesto: la base imponible de la línea. */
    public function getPrecioBaseAttribute(): float
    {
        $precio = (float) $this->precio_venta;

        return Config::preciosIncluyenImpuesto() && $this->afecto_impuesto
            ? round($precio - Config::impuestoDentroDe($precio), 2)
            : round($precio, 2);
    }

    /**
     * URL pública de la foto, o null si no tiene.
     *
     * En `imagen` se guarda la ruta relativa dentro del disco `public`
     * (`productos/xxx.jpg`), no la URL: así el archivo sigue encontrándose si
     * cambia el dominio o la carpeta desde la que se sirve.
     *
     * Se usa `asset()` y no `Storage::url()` a propósito: este último arma la
     * dirección con `APP_URL`, y el sistema se abre desde varias máquinas de la
     * red del negocio (RNF3). Con `APP_URL=http://localhost` las fotos se
     * romperían en todas menos en el servidor. `asset()` toma el host de la
     * petición en curso, así que la imagen se pide siempre al mismo sitio desde
     * el que se abrió la página.
     */
    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen ? asset('storage/'.$this->imagen) : null;
    }

    public function tieneImagen(): bool
    {
        return filled($this->imagen) && Storage::disk('public')->exists($this->imagen);
    }
}
