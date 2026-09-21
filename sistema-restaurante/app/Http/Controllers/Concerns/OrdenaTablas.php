<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Ordenación de las tablas de listado por la columna que elija quien mira.
 *
 * La columna llega en la URL (`?orden=precio&dir=desc`) y **nunca** se pasa tal
 * cual a la consulta: solo se aceptan las claves declaradas en la lista blanca
 * de cada pantalla. Sin esa lista, `?orden=` sería una vía de inyección SQL,
 * porque `orderBy()` no parametriza el nombre de la columna.
 *
 *     $orden = $this->orden($request, [
 *         'nombre' => 'nombre_completo',
 *         'cargo'  => Cargo::select('nombre')->whereColumn('cargos.id', 'empleados.cargo_id'),
 *     ], 'nombre');
 *
 *     $this->aplicarOrden($consulta, $orden)->paginate(10);
 *
 * El valor de cada clave puede ser el nombre de una columna o una subconsulta,
 * para ordenar por algo que vive en otra tabla.
 */
trait OrdenaTablas
{
    /**
     * Resuelve la ordenación pedida contra lo que la pantalla permite.
     *
     * @param  array<string, mixed>  $permitidas  clave visible en la URL => columna o subconsulta
     * @param  string  $defecto  clave a usar cuando no piden nada, o piden algo que no existe
     * @param  string  $dirDefecto  sentido cuando no se pide ninguno
     * @return array{clave: string, dir: string, columna: mixed}
     */
    protected function orden(Request $request, array $permitidas, string $defecto, string $dirDefecto = 'asc'): array
    {
        $pedida = $request->string('orden')->toString();
        $valida = array_key_exists($pedida, $permitidas);
        $clave = $valida ? $pedida : $defecto;

        if ($request->has('dir')) {
            // Cualquier cosa que no sea exactamente «desc» se trata como ascendente.
            $dir = mb_strtolower($request->string('dir')->toString()) === 'desc' ? 'desc' : 'asc';
        } else {
            // Sin `dir` en la URL: el sentido preferido de la pantalla solo manda
            // cuando tampoco se pidió columna, es decir, al entrar sin filtros.
            // Si pidieron columna a mano, ascendente.
            //
            // Esta distinción no es cosmética: el componente de cabecera decide
            // qué flecha pinta con la misma regla, y si las dos no coinciden la
            // flecha acaba diciendo lo contrario de lo que ordenan los datos.
            $dir = $valida ? 'asc' : $dirDefecto;
        }

        return [
            'clave' => $clave,
            'dir' => $dir,
            'columna' => $permitidas[$clave],
        ];
    }

    /**
     * Aplica la ordenación y deja un criterio de desempate.
     *
     * El desempate no es un adorno: sin él, dos filas con el mismo valor pueden
     * salir en distinto orden en cada consulta, y al paginar una fila aparece
     * dos veces mientras otra no aparece nunca.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $consulta
     * @param  array{clave: string, dir: string, columna: mixed}  $orden
     */
    protected function aplicarOrden(mixed $consulta, array $orden, string $desempate = 'id'): mixed
    {
        return $consulta
            ->orderBy($orden['columna'], $orden['dir'])
            ->orderBy($desempate, $orden['dir']);
    }
}
