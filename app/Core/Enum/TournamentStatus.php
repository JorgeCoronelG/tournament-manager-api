<?php

namespace App\Core\Enum;

/**
 * Planeación -> en curso (fase regular) -> liguilla -> finalizado. Sin liguillas, de en curso se pasa a finalizado.
 */
enum TournamentStatus: string
{
    case PLANNING = 'planning';
    case IN_PROGRESS = 'in_progress';
    case PLAYOFFS = 'playoffs';
    case FINISHED = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::PLANNING => 'En planeación',
            self::IN_PROGRESS => 'En curso',
            self::PLAYOFFS => 'Liguilla',
            self::FINISHED => 'Finalizado',
        };
    }
}
