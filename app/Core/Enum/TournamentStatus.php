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

    /**
     * Único estado al que se puede avanzar desde este (null si es el último).
     */
    public function next(): ?self
    {
        return match ($this) {
            self::PLANNING => self::IN_PROGRESS,
            self::IN_PROGRESS => self::FINISHED,
            self::FINISHED => null,
        };
    }
}
