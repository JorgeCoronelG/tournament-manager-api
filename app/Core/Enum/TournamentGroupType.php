<?php

namespace App\Core\Enum;

enum TournamentGroupType: string
{
    case REGULAR_STAGE = 'regular_stage';
    case PLAYOFF = 'playoff';

    public function label(): string
    {
        return match ($this) {
            self::REGULAR_STAGE => 'Fase regular',
            self::PLAYOFF => 'Eliminatoria',
        };
    }
}
