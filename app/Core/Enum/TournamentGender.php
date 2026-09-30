<?php

namespace App\Core\Enum;

enum TournamentGender: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Varonil',
            self::FEMALE => 'Femenil',
            self::MIXED => 'Mixto',
        };
    }
}
