<?php

namespace App\Helpers;

class Names
{
    /**
     * Forma comparable de un nombre: sin mayúsculas ni espacios repetidos o en los extremos.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
