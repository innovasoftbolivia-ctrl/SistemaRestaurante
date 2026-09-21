<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de operaciones sensibles (RNF6). Nada se borra: se registra.
 */
class Auditoria extends Model
{
    protected $table = 'auditoria';

    public $timestamps = false;

    protected $fillable = ['usuario_id', 'accion', 'entidad', 'entidad_id', 'detalle', 'ip', 'fecha'];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'fecha' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
