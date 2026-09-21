<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura, recibo o nota de venta, y con qué serie se numera cada uno
 * (`serie_por_omision_id`). La base exige que esa serie sea de este mismo tipo.
 */
class TipoComprobante extends Model
{
    protected $table = 'tipos_comprobante';

    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'aplica_persona', 'exige_cliente', 'exige_documento', 'activo',
        'serie_por_omision_id',
    ];

    protected function casts(): array
    {
        return [
            'exige_cliente' => 'boolean',
            'exige_documento' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function series(): HasMany
    {
        return $this->hasMany(SerieComprobante::class, 'tipo_comprobante_id');
    }

    /** La serie con que se numera este tipo de documento. */
    public function seriePorOmision(): BelongsTo
    {
        return $this->belongsTo(SerieComprobante::class, 'serie_por_omision_id');
    }
}
