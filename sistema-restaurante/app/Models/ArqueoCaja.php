<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del arqueo del cierre: cuántos billetes (o monedas) de una
 * denominación contó el cajero. El efectivo contado del turno es su suma.
 *
 * Solo se lee: las filas las escribe Cajas::cerrar, dentro del cierre.
 */
class ArqueoCaja extends Model
{
    protected $table = 'arqueo_caja';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = ['sesion_caja_id', 'denominacion', 'cantidad'];

    /**
     * Los billetes y monedas bolivianos en circulación, de mayor a menor. Lo
     * que es billete se muestra aparte de lo que es moneda.
     */
    public const BILLETES = [200, 100, 50, 20, 10];

    public const MONEDAS = [5, 2, 1, 0.5, 0.2, 0.1];

    protected function casts(): array
    {
        return ['denominacion' => 'float', 'cantidad' => 'integer'];
    }

    /** @return array<int, float> todas, de mayor a menor */
    public static function denominaciones(): array
    {
        return array_map('floatval', [...self::BILLETES, ...self::MONEDAS]);
    }

    /** La clave con la que viaja en el formulario: «200», «0.5». */
    public static function clave(float $denominacion): string
    {
        return rtrim(rtrim(number_format($denominacion, 2, '.', ''), '0'), '.');
    }

    public function getSubtotalAttribute(): float
    {
        return round($this->denominacion * $this->cantidad, 2);
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }
}
