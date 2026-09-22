<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * El plato se hace en la casa y no lleva stock ni costo. Lo que se compra
 * hecho y se revende —las bebidas embotelladas— sí (`controla_stock`): se
 * compra por empaque al proveedor, se vende por unidad, y su último costo de
 * compra (`costo`) se congela en cada venta para calcular la ganancia. El
 * stock lo mueve solo App\Services\Inventario.
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
        'controla_stock', 'stock_minimo', 'contenido_empaque', 'nombre_empaque', 'costo',
        'imagen', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'afecto_impuesto' => 'boolean',
            'controla_stock' => 'boolean',
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'contenido_empaque' => 'decimal:3',
            'costo' => 'decimal:4',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    /** Lo que lleva inventario (se compra hecho): las bebidas embotelladas. */
    public function scopeConStock(Builder $query): Builder
    {
        return $query->where('controla_stock', 1);
    }

    /** Lleva stock y está en el mínimo o por debajo: hay que comprar. */
    public function getBajoMinimoAttribute(): bool
    {
        return $this->controla_stock && (float) $this->stock_actual <= (float) $this->stock_minimo;
    }

    /** «Caja de 12», o null si no viene en empaque. */
    public function getEmpaqueVisibleAttribute(): ?string
    {
        if (! $this->contenido_empaque || ! $this->nombre_empaque) {
            return null;
        }

        return $this->nombre_empaque.' de '.Config::cantidad($this->contenido_empaque);
    }

    /**
     * El stock dicho como se cuenta en la bodega: «3 cajas y 4 sueltas»
     * cuando viene en empaque. Negativo, tal cual (se vendió sin registrar la
     * compra: hay que revisarlo).
     */
    public function getStockEnEmpaquesAttribute(): string
    {
        return $this->enEmpaques((float) $this->stock_actual);
    }

    /**
     * Una cantidad de unidades dicha en empaques: «3 cajas de 12 y 2 sueltas».
     * Se pluraliza la primera palabra del empaque («Caja de 12» → «cajas de
     * 12»). Negativo o sin empaque, en unidades.
     */
    public function enEmpaques(float $cantidad): string
    {
        $contenido = (float) $this->contenido_empaque;

        if ($contenido <= 1 || $cantidad <= 0 || ! $this->nombre_empaque) {
            return Config::cantidad($cantidad).' u.';
        }

        $cajas = (int) floor($cantidad / $contenido + 1e-9);
        $sueltas = round($cantidad - $cajas * $contenido, 3);

        $empaque = mb_strtolower($this->nombre_empaque);
        if ($cajas !== 1) {
            $empaque = preg_replace_callback('/^(\S+)/u', fn ($m) => $m[1].(preg_match('/[lrndj]$/u', $m[1]) ? 'es' : 's'), $empaque);
        }

        return trim(($cajas > 0 ? $cajas.' '.$empaque : '')
            .($cajas > 0 && $sueltas > 0 ? ' y ' : '')
            .($sueltas > 0 || $cajas === 0 ? Config::cantidad($sueltas).' suelta'.($sueltas == 1 ? '' : 's') : ''));
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
