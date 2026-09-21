<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Un documento de identidad: CI, NIT, carné de extranjería...
 *
 * La clave es el código, el mismo que guardan clientes y empleados y el que
 * se imprime. `aplica_*` dice para quién vale, y la base lo exige con una FK
 * compuesta: la lista vive aquí y en ningún otro lado (antes eran tres ENUM).
 * El comprobante no apunta aquí: guarda una foto del código al emitir.
 */
class TipoDocumento extends Model
{
    protected $table = 'tipos_documento';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'aplica_natural', 'aplica_juridica', 'aplica_empleado', 'orden'];

    protected function casts(): array
    {
        return [
            'aplica_natural' => 'boolean',
            'aplica_juridica' => 'boolean',
            'aplica_empleado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /** Para quién: la columna `aplica_*` de cada uno. */
    private const PARA = [
        'NATURAL' => 'aplica_natural',
        'JURIDICA' => 'aplica_juridica',
        'EMPLEADO' => 'aplica_empleado',
    ];

    /** @param  'NATURAL'|'JURIDICA'|'EMPLEADO'  $para */
    public function scopePara(Builder $query, string $para): Builder
    {
        return $query->where(self::PARA[$para], 1)->orderBy('orden')->orderBy('codigo');
    }

    /**
     * Código => nombre, en su orden, para un select.
     *
     * @param  'NATURAL'|'JURIDICA'|'EMPLEADO'  $para
     * @return array<string, string>
     */
    public static function opciones(string $para): array
    {
        return self::query()->para($para)->pluck('nombre', 'codigo')->all();
    }

    /**
     * La regla de validación: un documento que exista y valga para ese tipo
     * de persona. La misma que exige la FK, dicha antes para dar un mensaje.
     *
     * @param  'NATURAL'|'JURIDICA'|'EMPLEADO'  $para
     */
    public static function regla(string $para): Exists
    {
        return Rule::exists('tipos_documento', 'codigo')->where(self::PARA[$para], 1);
    }
}
