<?php

namespace App\Core\Enum;

enum Role: int
{
    case SUPERADMIN = 1;
    case LEAGUE_ADMIN = 2;
    case REFEREE = 3;
    case MANAGER = 4;
    case PLAYER = 5;

    public function label(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Super Administrador',
            self::LEAGUE_ADMIN => 'Administrador Liga',
            self::REFEREE => 'Árbitro',
            self::MANAGER => 'Director Técnico',
            self::PLAYER => 'Jugador',
        };
    }
}
