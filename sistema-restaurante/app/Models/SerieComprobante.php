<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La serie determina el tipo de documento: por eso `comprobantes` no guarda
 * `tipo_comprobante_id` (sería una segunda fuente de verdad).
 */
class SerieComprobante extends Model
{
    protected $table = 'series_comprobante';

    public $timestamps = false;

    protected $fillable = ['tipo_comprobante_id', 'serie', 'correlativo_actual', 'longitud', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class, 'tipo_comprobante_id');
    }
}
