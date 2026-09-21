<?php

namespace App\Support;

/**
 * El color de cada categoría del menú: la franja y el punto en el mostrador,
 * y la inicial de los platos sin foto en el listado del menú. Una sola lista
 * para que una categoría tenga el mismo color en todas partes.
 *
 * Sale del id y no se guarda: el negocio no lo elige, y así no hay una
 * columna más que mantener. Cinco colores que se distinguen bien entre sí;
 * con más categorías se repiten.
 */
class ColorCategoria
{
    /** Tono fuerte: franjas y puntos. */
    public const SOLIDOS = [
        'bg-theme-purple-500',
        'bg-blue-light-500',
        'bg-success-500',
        'bg-orange-500',
        'bg-theme-pink-500',
    ];

    /** Fondo suave con borde del mismo color, para poner texto encima. */
    public const SUAVES = [
        'bg-theme-purple-500/15 ring-theme-purple-500/40',
        'bg-blue-light-500/15 ring-blue-light-500/40',
        'bg-success-500/15 ring-success-500/40',
        'bg-orange-500/15 ring-orange-500/40',
        'bg-theme-pink-500/15 ring-theme-pink-500/40',
    ];

    public static function solido(?int $categoriaId): string
    {
        return $categoriaId ? self::SOLIDOS[$categoriaId % count(self::SOLIDOS)] : 'bg-gray-300 dark:bg-gray-700';
    }

    public static function suave(?int $categoriaId): string
    {
        return $categoriaId ? self::SUAVES[$categoriaId % count(self::SUAVES)] : 'bg-gray-100 ring-gray-300 dark:bg-white/5 dark:ring-gray-700';
    }
}
