<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * La cuenta de acceso. Los datos de la persona viven en {@see Empleado};
 * aquí solo está lo que tiene que ver con entrar al sistema.
 *
 * `activo` es el acceso al sistema, no el vínculo laboral.
 */
class Usuario extends Authenticatable
{
    protected $table = 'usuarios';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    /** La tabla no tiene columna remember_token; se desactiva la funcionalidad. */
    protected $rememberTokenName = '';

    protected $fillable = [
        'empleado_id', 'rol_id', 'usuario', 'password_hash',
        'password_actualizado_en', 'debe_cambiar_password', 'activo',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
            'password_actualizado_en' => 'datetime',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    /** Laravel espera `password`; el esquema la llama `password_hash`. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'usuario_id');
    }

    /** Turnos de caja que esta cuenta abrió. */
    public function sesionesCaja(): HasMany
    {
        return $this->hasMany(SesionCaja::class, 'usuario_apertura_id');
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
            $q->where('usuario', 'like', "%{$texto}%")
                ->orWhereHas('empleado', fn (Builder $e) => $e->where('nombre_completo', 'like', "%{$texto}%"));
        });
    }

    /**
     * Códigos de permiso que otorga el rol de esta cuenta. Un rol desactivado
     * no otorga nada: «desactivar» un rol con cuentas tiene que quitarles el
     * acceso, no dejarlo igual con otra etiqueta.
     */
    public function permisos(): array
    {
        if (! $this->rol?->activo) {
            return [];
        }

        return $this->rol->permisos->pluck('codigo')->all();
    }

    public function tienePermiso(string $codigo): bool
    {
        return in_array($codigo, $this->permisos(), true);
    }

    /**
     * Puede entrar solo si la cuenta está activa y el vínculo laboral
     * del empleado sigue vigente.
     */
    public function puedeIngresar(): bool
    {
        return $this->activo && $this->empleado?->estado === 'ACTIVO' && (bool) $this->rol?->activo;
    }

    public function getNombreCompletoAttribute(): string
    {
        return $this->empleado?->nombre_completo ?? $this->usuario;
    }
}
