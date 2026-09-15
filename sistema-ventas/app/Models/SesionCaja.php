<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un turno de caja: se abre con un monto inicial y se cierra con el arqueo.
 * `monto_esperado` y `diferencia` los calcula la base al cerrar.
 */
class SesionCaja extends Model
{
    protected $table = 'sesiones_caja';

    public $timestamps = false;

    protected $fillable = [
        'caja_id', 'usuario_apertura_id', 'usuario_cierre_id',
        'fecha_apertura', 'fecha_cierre', 'monto_inicial',
        'monto_esperado', 'monto_declarado', 'estado', 'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
            'monto_inicial' => 'decimal:2',
            'monto_esperado' => 'decimal:2',
            'monto_declarado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_apertura_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_cierre_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'sesion_caja_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'sesion_caja_id');
    }

    /** Devoluciones pagadas desde este cajón; salen del efectivo esperado. */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'sesion_caja_id');
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('estado', 'ABIERTA');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'ABIERTA';
    }

    /**
     * Lo que debería haber en el cajón ahora mismo, con la misma fórmula que
     * usa `sp_cerrar_caja`. Sirve para mostrarlo antes de cerrar.
     */
    public function efectivoEsperado(): float
    {
        $ventas = (float) $this->ventas()
            ->where('ventas.estado', '<>', 'ANULADA')
            ->join('venta_pagos', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->join('metodos_pago', 'metodos_pago.id', '=', 'venta_pagos.metodo_pago_id')
            ->where('metodos_pago.afecta_caja', 1)
            ->sum('venta_pagos.monto');

        $ingresos = (float) $this->movimientos()->where('tipo', 'INGRESO')->sum('monto');
        $egresos = (float) $this->movimientos()->where('tipo', 'EGRESO')->sum('monto');

        /*
         * De cada devolución sale del cajón solo la fracción que en su día entró
         * en efectivo: una venta cobrada con tarjeta se reembolsa por el mismo
         * medio, y descontarla de aquí dejaría al cajero con un sobrante.
         *
         * La fórmula es la misma que la de `sp_cerrar_caja`, y tiene que
         * seguir siéndolo: esta pantalla enseña el esperado y aquel procedimiento
         * lo firma al cerrar. Si se separan, el cajero ve un número y el arqueo
         * registra otro.
         */
        $devuelto = (float) $this->devoluciones()
            ->join('ventas', 'ventas.id', '=', 'devoluciones.venta_id')
            ->selectRaw(
                'IFNULL(SUM(IFNULL(devoluciones.efectivo, ROUND(devoluciones.total * IFNULL('.
                '(SELECT SUM(vp.monto) FROM venta_pagos vp '.
                'JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id '.
                'WHERE vp.venta_id = devoluciones.venta_id AND mp.afecta_caja = 1)'.
                ' / NULLIF(ventas.total, 0), 0), 2))), 0) AS efectivo'
            )
            ->value('efectivo');

        return round((float) $this->monto_inicial + $ventas + $ingresos - $egresos - $devuelto, 2);
    }
}
