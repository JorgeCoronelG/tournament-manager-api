<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class Names
{
    /**
     * Forma comparable de un nombre: sin mayúsculas, acentos ni espacios repetidos o en los extremos
     * ("Águilas  FC" y "aguilas fc" son el mismo nombre). La ñ cuenta como n.
     */
    public static function normalize(string $name): string
    {
        return Str::ascii(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name))));
    }

    /**
     * Si alguno de los nombres existentes equivale a `$name` según `normalize`.
     *
     * @param  iterable<mixed>  $existing
     */
    public static function anyMatches(iterable $existing, string $name): bool
    {
        $wanted = self::normalize($name);

        foreach ($existing as $candidate) {
            if (is_string($candidate) && self::normalize($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
