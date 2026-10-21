<?php

namespace App\Core\Enum;

enum TournamentGender: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case MIXED = 'mixed';

    /**
     * Si un torneo de esta rama admite a un jugador de la rama indicada (el mixto admite ambas).
     */
    public function accepts(PlayerGender $gender): bool
    {
        return $this === self::MIXED || $this->value === $gender->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Varonil',
            self::FEMALE => 'Femenil',
            self::MIXED => 'Mixto',
        };
    }
}
