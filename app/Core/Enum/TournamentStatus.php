<?php

namespace App\Core\Enum;

enum TournamentStatus: string
{
    case PLANNING = 'planning';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::PLANNING => 'En planeación',
            self::IN_PROGRESS => 'En curso',
            self::FINISHED => 'Finalizado',
        };
    }
}
