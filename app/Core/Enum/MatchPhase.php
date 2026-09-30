<?php

namespace App\Core\Enum;

enum MatchPhase: string
{
    case REGULAR_STAGE = 'regular_stage';
    case ROUND_OF_16 = 'round_of_16';
    case QUARTERFINAL = 'quarterfinal';
    case SEMIFINAL = 'semifinal';
    case FINAL = 'final';

    public function label(): string
    {
        return match ($this) {
            self::REGULAR_STAGE => 'Fase regular',
            self::ROUND_OF_16 => 'Octavos de final',
            self::QUARTERFINAL => 'Cuartos de final',
            self::SEMIFINAL => 'Semifinal',
            self::FINAL => 'Final',
        };
    }
}
