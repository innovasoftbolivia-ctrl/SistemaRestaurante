<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Lo que ve la persona cuando una operación falla.
 *
 * Los servicios avisan con RuntimeException y un texto pensado para el
 * mostrador. Pero QueryException también es una RuntimeException: sin este
 * filtro, un error de la base llegaba a la pantalla con la consulta entera.
 */
class Mensaje
{
    public const GENERICO = 'No se pudo completar la operación. Revisa los datos e inténtalo de nuevo.';

    /** El texto del servicio, o el de la base si es un error de la base. */
    public static function de(Throwable $e, string $generico = self::GENERICO): string
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            return self::deLaBase($e, $generico);
        }

        return $e->getMessage();
    }

    /** Los triggers avisan con SIGNAL: se muestra su texto y no el ruido de PDO. */
    public static function deLaBase(Throwable $e, string $generico = self::GENERICO): string
    {
        if (preg_match('/SQLSTATE\[45000\].*?:\s*\d+\s+(.+?)(?: \(Connection:|$)/s', $e->getMessage(), $m)) {
            return trim($m[1]);
        }

        report($e);

        return $generico;
    }
}
