<?php

namespace App\Core\Enum;

/**
 * Catálogo de deportes. Para agregar uno basta un case aquí y su valor en el enum de las columnas `sport`.
 */
enum Sport: string
{
    case FOOTBALL = 'football';

    public static function default(): self
    {
        return self::FOOTBALL;
    }

    public function label(): string
    {
        return match ($this) {
            self::FOOTBALL => 'Fútbol',
        };
    }
}
