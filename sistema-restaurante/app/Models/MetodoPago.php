<?php

namespace App\Models;

use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `afecta_caja` distingue el dinero que queda físicamente en el cajón
 * (efectivo) del que no (tarjeta, transferencia). Solo el primero cuenta
 * para el arqueo de cierre, y solo el primero admite vuelto: es la única
 * definición de «efectivo» del sistema.
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

    /**
     * El dinero que entra al cajón es el que admite monto recibido y vuelto.
     * Lo dice `afecta_caja`, la misma columna que suma en el arqueo: antes el
     * vuelto miraba el código `EFECTIVO`, y un segundo método de caja (dólares,
     * por ejemplo) entraba al arqueo pero no daba vuelto.
     */
    public function esEfectivo(): bool
    {
        return (bool) $this->afecta_caja;
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
