<?php

namespace App\Models;

use App\Services\ReglasEnPhp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La persona y su vínculo laboral. Un empleado puede no tener usuario
 * (trabaja en el negocio pero no entra al sistema).
 *
 * `estado` es el vínculo laboral, no el acceso: al pasar a CESADO o
 * SUSPENDIDO un trigger de la base desactiva la cuenta asociada. Donde no hay
 * triggers, lo hace el evento de abajo (ver config/ventas.php).
 */
class Empleado extends Model
{
    protected static function booted(): void
    {
        // Réplica de trg_empleados_after_update. Va en el modelo y no en el
        // controlador para que valga por cualquier camino que cambie el
        // estado, igual que el trigger.
        static::updated(function (self $empleado) {
            if (! ReglasEnPhp::activa()) {
                return;
            }

            ReglasEnPhp::despuesDeActualizarEmpleado(
                $empleado->id,
                (string) $empleado->getOriginal('estado'),
                (string) $empleado->estado,
            );
        });
    }

    protected $table = 'empleados';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const TIPOS_DOCUMENTO = ['CI', 'CE', 'PAS'];

    public const TIPOS_CONTRATO = ['INDEFINIDO', 'PLAZO_FIJO', 'PARCIAL', 'PRACTICAS'];

    public const ESTADOS = ['ACTIVO', 'SUSPENDIDO', 'CESADO'];

    protected $fillable = [
        'cargo_id', 'tipo_documento', 'documento', 'nombres', 'apellidos',
        'fecha_nacimiento', 'telefono', 'email', 'direccion',
        'fecha_ingreso', 'fecha_cese', 'motivo_cese', 'tipo_contrato', 'estado',
    ];

    // Nota: `nombre_completo` es una columna generada por MySQL. Al no estar en
    // $fillable nunca se escribe desde la aplicación; solo se lee.

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date:Y-m-d',
            'fecha_ingreso' => 'date:Y-m-d',
            'fecha_cese' => 'date:Y-m-d',
        ];
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class, 'cargo_id');
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class, 'empleado_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', 'ACTIVO');
    }

    /** Búsqueda por nombre, apellidos o documento. */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre_completo', 'like', "%{$texto}%")
                ->orWhere('documento', 'like', "%{$texto}%")
                ->orWhere('email', 'like', "%{$texto}%");
        });
    }

    public function getAntiguedadAttribute(): ?string
    {
        return $this->fecha_ingreso?->diffForHumans(null, true);
    }
}
