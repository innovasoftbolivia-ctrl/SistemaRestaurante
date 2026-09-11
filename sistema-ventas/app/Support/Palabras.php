<?php

namespace App\Support;

/**
 * Las reglas del castellano que el sistema necesita para no escribir mal.
 *
 * Existe porque hay texto que se arma con palabras que escribe el usuario —el
 * nombre de un empaque: «Caja», «Saco», «Cartón»— y pegarle una «s» a lo que
 * sea produce «cartóns» en la pantalla que el comerciante mira todos los días.
 */
class Palabras
{
    /**
     * Plural de una palabra suelta, en minúsculas.
     *
     * Se aplican las reglas que hacen falta de verdad para estas palabras:
     * vocal + s (caja→cajas), z → ces (haz→haces), aguda acabada en -ón/-ín/-án
     * que pierde la tilde al alargarse (cartón→cartones), y consonante + es
     * (turril→turriles). Los préstamos del inglés de uso corriente en el rubro
     * quedan fuera porque «packes» no lo diría nadie.
     */
    public static function plural(string $palabra): string
    {
        $palabra = mb_strtolower(trim($palabra));

        if ($palabra === '') {
            return $palabra;
        }

        if (preg_match('/(pack|display|blister|six)$/u', $palabra)) {
            return $palabra.'s';
        }

        if (preg_match('/[aeiou]$/u', $palabra)) {
            return $palabra.'s';
        }

        if (str_ends_with($palabra, 'z')) {
            return mb_substr($palabra, 0, -1).'ces';
        }

        // Al alargarse, la palabra deja de necesitar la tilde: el acento ya no
        // cae en la última sílaba.
        $sinTilde = strtr(mb_substr($palabra, -2), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return mb_substr($palabra, 0, -2).$sinTilde.'es';
    }
}
