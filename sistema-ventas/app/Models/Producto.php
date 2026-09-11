<?php

namespace App\Models;

use App\Services\Inventario;
use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Un artículo del catálogo.
 *
 * Sobre los precios: `precio_compra` y `precio_venta` se guardan SIN impuesto.
 * El precio que ve el cliente en el estante es `precio_venta * (1 + tasa)`, y
 * se calcula al vuelo con la tasa vigente en `configuracion`.
 *
 * Sobre el stock: `stock_actual` NO se edita a mano. Cambia solo a través de
 * {@see Inventario}, que deja siempre un movimiento con su
 * responsable y su motivo.
 *
 * Sobre el empaque: el negocio compra por caja y vende por unidad. La regla
 * que ordena todo el modelo es que el stock, el precio y cada movimiento se
 * cuentan SIEMPRE en la unidad de venta (`unidad_medida_id`). El empaque
 * —`contenido_empaque` unidades dentro de un `nombre_empaque`— no es una
 * segunda unidad de stock: es solo la equivalencia que deja escribir «3 cajas
 * y 5 sueltas» en vez de hacer la multiplicación de cabeza. Los dos campos van
 * juntos o no van: un producto a granel los tiene en NULL.
 */
class Producto extends Model
{
    protected $table = 'productos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'categoria_id', 'unidad_medida_id', 'proveedor_id',
        'contenido_empaque', 'nombre_empaque',
        'codigo', 'codigo_barras', 'nombre', 'descripcion',
        'precio_compra', 'precio_venta', 'afecto_impuesto',
        'stock_minimo', 'imagen', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_compra' => 'decimal:2',
            'precio_venta' => 'decimal:2',
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'contenido_empaque' => 'integer',
            'afecto_impuesto' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    // ------------------------------------------------------------- consultas

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    /** Busca por nombre, código interno o código de barras (RNF2: lector + Enter). */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('codigo', 'like', "%{$texto}%")
                ->orWhere('codigo_barras', 'like', "%{$texto}%");
        });
    }

    /** Productos que llegaron a su stock mínimo (O7: alerta de quiebre). */
    public function scopeBajoMinimo(Builder $query): Builder
    {
        return $query->whereColumn('stock_actual', '<=', 'stock_minimo');
    }

    /**
     * Las mismas filas y columnas que la vista `v_alertas_stock`, como
     * consulta.
     *
     * Era la única vista que la aplicación leía de verdad (las demás se citan
     * en los comentarios como definición de referencia, pero los reportes ya
     * consultan las tablas base). Se reescribió porque un hosting compartido
     * puede denegar `CREATE VIEW` —InfinityFree lo hace, con el error 1142— y
     * sin esto el panel y los reportes se caían enteros.
     *
     * `ReportesTest` compara esta consulta contra la vista del esquema, para
     * que las dos no se separen donde la vista sí existe.
     */
    public static function alertasDeStock(): \Illuminate\Database\Query\Builder
    {
        return DB::table('productos as p')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', 1)
            ->whereColumn('p.stock_actual', '<=', 'p.stock_minimo')
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria')
            ->selectRaw('p.stock_actual, p.stock_minimo, (p.stock_minimo - p.stock_actual) AS faltante');
    }

    // --------------------------------------------------------------- empaque

    /** ¿Llega del proveedor dentro de un empaque con varias unidades? */
    public function tieneEmpaque(): bool
    {
        return $this->contenido_empaque !== null && $this->contenido_empaque > 1 && filled($this->nombre_empaque);
    }

    /** «Caja de 24 UND», para decir de una vez de qué empaque se habla. */
    public function getEtiquetaEmpaqueAttribute(): ?string
    {
        if (! $this->tieneEmpaque()) {
            return null;
        }

        return "{$this->nombre_empaque} de {$this->contenido_empaque} ".($this->unidadMedida?->codigo ?? '');
    }

    /** El nombre del empaque en plural: «cajas», «planchas», «cartones». */
    public function getEmpaquePluralAttribute(): ?string
    {
        return $this->tieneEmpaque() ? self::pluralizar($this->nombre_empaque) : null;
    }

    /**
     * Cuántas unidades de venta suman N empaques más M sueltas.
     *
     * Es la única cuenta que hace falta para el ingreso por caja, y vive aquí
     * —y no en cada controlador— para que las dos pantallas que ingresan
     * mercadería no puedan discrepar.
     */
    public function unidadesDe(float $empaques, float $sueltas = 0): float
    {
        return round($empaques * (int) $this->contenido_empaque + $sueltas, 3);
    }

    /**
     * Cómo se dice una cantidad en el almacén: 77 unidades de un producto que
     * viene en cajas de 24 son «3 cajas y 5 sueltas».
     *
     * Se calcula del stock, no se guarda: por eso las cajas bajan solas a
     * medida que el mostrador despacha unidades. Vender 24 de un producto que
     * viene de 24 descuenta una caja entera sin que nadie haga nada.
     *
     * Por debajo de un empaque completo dice solo las sueltas —«5 sueltas»—,
     * porque «0 cajas y 5 sueltas» es la misma información con una cifra de
     * más. Devuelve null si el producto no viene en empaque o si no queda
     * stock: de un agotado no hay nada que desglosar.
     */
    public function desglosar(int|float|string|null $cantidad): ?string
    {
        if (! $this->tieneEmpaque()) {
            return null;
        }

        $cantidad = (float) $cantidad;

        if ($cantidad <= 0) {
            return null;
        }

        $enteros = (int) floor($cantidad / $this->contenido_empaque);
        $sueltas = round($cantidad - $enteros * $this->contenido_empaque, 3);

        $texto = $enteros > 0
            ? $enteros.' '.($enteros === 1 ? mb_strtolower($this->nombre_empaque) : $this->empaque_plural)
            : null;

        $resto = $sueltas > 0
            ? Config::cantidad($sueltas).' '.($sueltas === 1.0 ? 'suelta' : 'sueltas')
            : null;

        return $texto && $resto ? "{$texto} y {$resto}" : ($texto ?? $resto);
    }

    /** El desglose del stock que hay ahora mismo. */
    public function getStockDesglosadoAttribute(): ?string
    {
        return $this->desglosar($this->stock_actual);
    }

    /**
     * Plural del nombre del empaque.
     *
     * El campo es texto libre —cada rubro llama distinto a su empaque— así que
     * no alcanza con pegarle una «s». Se aplican las reglas del castellano que
     * hacen falta de verdad para estas palabras: vocal + s (caja→cajas),
     * z → ces (haz→haces), aguda acabada en -ón/-ín/-án que pierde la tilde
     * (cartón→cartones), y consonante + es (pack→packs queda como excepción
     * porque es un préstamo y «packes» no lo diría nadie).
     */
    private static function pluralizar(string $palabra): string
    {
        $palabra = mb_strtolower(trim($palabra));

        if ($palabra === '') {
            return $palabra;
        }

        // Préstamos del inglés de uso corriente en el rubro: plural con «s».
        if (preg_match('/(pack|display|blister|six)$/u', $palabra)) {
            return $palabra.'s';
        }

        if (preg_match('/[aeiou]$/u', $palabra)) {
            return $palabra.'s';
        }

        if (str_ends_with($palabra, 'z')) {
            return mb_substr($palabra, 0, -1).'ces';
        }

        // Aguda terminada en -ón, -ín, -án…: al alargarse deja de necesitar la
        // tilde, porque el acento ya no cae en la última sílaba.
        $sinTilde = strtr(mb_substr($palabra, -2), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return mb_substr($palabra, 0, -2).$sinTilde.'es';
    }

    // ------------------------------------------------------------- derivados

    /** Precio que ve el cliente: base + impuesto, si el producto está afecto. */
    public function getPrecioEstanteAttribute(): float
    {
        $base = (float) $this->precio_venta;

        return $this->afecto_impuesto
            ? round($base * (1 + Config::tasaImpuesto()), 2)
            : round($base, 2);
    }

    /** Ganancia por unidad sobre la base imponible. */
    public function getMargenAttribute(): float
    {
        return round((float) $this->precio_venta - (float) $this->precio_compra, 2);
    }

    /** Margen en porcentaje del precio de venta. */
    public function getMargenPorcentajeAttribute(): ?float
    {
        $venta = (float) $this->precio_venta;

        return $venta > 0 ? round($this->margen / $venta * 100, 1) : null;
    }

    public function getSinStockAttribute(): bool
    {
        return (float) $this->stock_actual <= 0;
    }

    public function getBajoMinimoAttribute(): bool
    {
        return (float) $this->stock_actual <= (float) $this->stock_minimo;
    }

    /** Cuánto capital está inmovilizado en este producto. */
    public function getValorInventarioAttribute(): float
    {
        return round((float) $this->stock_actual * (float) $this->precio_compra, 2);
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
