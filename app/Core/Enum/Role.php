<?php

namespace App\Core\Enum;

enum Role: int
{
    case SUPERADMIN = 1;
    case LEAGUE_ADMIN = 2;
    case REFEREE = 3;
    case MANAGER = 4;
    case PLAYER = 5;

    /**
     * Roles que pueden iniciar sesión en cada cliente. Con varios roles basta que uno cumpla.
     *
     * @return list<self>
     */
    public static function allowedForClient(LoginClient $client): array
    {
        return match ($client) {
            LoginClient::WEB => [self::SUPERADMIN, self::LEAGUE_ADMIN],
            LoginClient::MOBILE => [self::REFEREE, self::MANAGER, self::PLAYER],
        };
    }

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

    /**
     * Identificador estable que consume el front; no cambia aunque se
     * renombre la etiqueta en la tabla `roles`.
     */
    public function code(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'superadmin',
            self::LEAGUE_ADMIN => 'league_admin',
            self::REFEREE => 'referee',
            self::MANAGER => 'manager',
            self::PLAYER => 'player',
        };
    }
}
