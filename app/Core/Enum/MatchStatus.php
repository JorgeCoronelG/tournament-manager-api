<?php

namespace App\Core\Enum;

enum MatchStatus: string
{
    case SCHEDULED = 'scheduled';
    case PLAYED = 'played';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Programado',
            self::PLAYED => 'Jugado',
            self::CANCELLED => 'Cancelado',
        };
    }
}
