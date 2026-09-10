<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un solo maestro con discriminador `tipo_persona`:
 *   NATURAL  -> nombres + apellidos, CI/CE/PAS. Recibe RECIBO.
 *   JURIDICA -> razón social + NIT + dirección fiscal. Recibe FACTURA.
 *
 * Registrarlo es opcional: la venta al paso va sin cliente y el comprobante
 * sale a nombre genérico. Solo la factura exige identificarlo.
 */
class Cliente extends Model
{
    protected $table = 'clientes';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const TIPOS_PERSONA = ['NATURAL', 'JURIDICA'];

    public const DOCUMENTOS_NATURAL = ['CI', 'CE', 'PAS', 'SIN'];

    protected $fillable = [
        'tipo_persona', 'tipo_documento', 'documento',
        'nombres', 'apellidos', 'fecha_nacimiento',
        'razon_social', 'nombre_comercial', 'representante_legal',
        'direccion', 'telefono', 'email', 'activo',
    ];

    // `nombre` es columna generada por MySQL: se lee, nunca se escribe.

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date:Y-m-d',
            'activo' => 'boolean',
        ];
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'cliente_id');
    }

    public function esJuridica(): bool
    {
        return $this->tipo_persona === 'JURIDICA';
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('documento', 'like', "%{$texto}%")
                ->orWhere('nombre_comercial', 'like', "%{$texto}%");
        });
    }

    public function getEtiquetaAttribute(): string
    {
        $documento = $this->documento ? " · {$this->tipo_documento} {$this->documento}" : '';

        return $this->nombre.$documento;
    }
}
