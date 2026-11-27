<?php

namespace App\Core\Enum;

/**
 * Tiempo del partido en que pasó un evento. El tiempo extra solo se juega en finales empatadas.
 */
enum MatchPeriod: string
{
    case FIRST_HALF = 'first_half';
    case SECOND_HALF = 'second_half';
    case EXTRA_FIRST = 'extra_first';
    case EXTRA_SECOND = 'extra_second';

    public function label(): string
    {
        return match ($this) {
            self::FIRST_HALF => 'Primer tiempo',
            self::SECOND_HALF => 'Segundo tiempo',
            self::EXTRA_FIRST => 'Tiempo extra 1',
            self::EXTRA_SECOND => 'Tiempo extra 2',
        };
    }
}
