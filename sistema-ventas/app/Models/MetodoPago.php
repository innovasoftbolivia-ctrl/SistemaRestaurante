<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `afecta_caja` distingue el dinero que queda físicamente en el cajón
 * (efectivo) del que no (tarjeta, transferencia). Solo el primero cuenta
 * para el arqueo de cierre.
 */
class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'afecta_caja', 'activo'];

    protected function casts(): array
    {
        return [
            'afecta_caja' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function esEfectivo(): bool
    {
        return $this->codigo === 'EFECTIVO';
    }

    /**
     * Tarjeta, billetera o transferencia: el dinero cae en el banco y solo el
     * número de operación permite conciliarlo. El QR ya lo trae su cobro.
     */
    public function requiereReferencia(): bool
    {
        return ! $this->esEfectivo() && $this->codigo !== 'QR' && self::exigeReferencia();
    }

    public static function exigeReferencia(): bool
    {
        return (string) Config::get('exigir_referencia_pago', '1') === '1';
    }
}
