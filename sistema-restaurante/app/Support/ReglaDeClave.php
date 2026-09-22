<?php

namespace App\Support;

use Closure;

/**
 * Lo que una contraseña nueva no puede ser, además de corta: el propio nombre
 * de usuario (la primera que se prueba) ni una de las de siempre. Ocho
 * caracteres de largo mínimo los pone `Password::min(8)` junto a esta regla.
 */
class ReglaDeClave
{
    /** Las que se prueban primero en cualquier sistema. */
    private const COMUNES = [
        '12345678', '123456789', '1234567890', '87654321', '11111111', '00000000',
        'password', 'contraseña', 'contrasena', 'qwertyui', 'qwerty123', 'abc12345',
        'restaurante', 'bolivia123',
    ];

    /** @return Closure(string, mixed, Closure): void */
    public static function para(?string $usuario): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) use ($usuario) {
            $clave = mb_strtolower((string) $valor);

            if ($usuario !== null && $usuario !== '' && str_contains($clave, mb_strtolower($usuario))) {
                $fallar('La contraseña no puede contener el nombre de usuario.');

                return;
            }

            if (in_array($clave, self::COMUNES, true)) {
                $fallar('Esa contraseña es de las primeras que se prueban: elige otra.');
            }
        };
    }
}
