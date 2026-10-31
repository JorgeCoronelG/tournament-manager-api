<?php

namespace App\Core\Enum;

enum WalkoverCause: string
{
    case NO_SHOW = 'no_show';
    case TEAM_WITHDRAWN = 'team_withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::NO_SHOW => 'No se presentó',
            self::TEAM_WITHDRAWN => 'Equipo dado de baja',
        };
    }
}
