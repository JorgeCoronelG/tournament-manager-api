<?php

namespace App\Core\Enum;

/**
 * Rama del jugador. Mismos valores que TournamentGender (sin el mixto) para compararlos directo.
 */
enum PlayerGender: string
{
    case MALE = 'male';
    case FEMALE = 'female';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Varonil',
            self::FEMALE => 'Femenil',
        };
    }
}
