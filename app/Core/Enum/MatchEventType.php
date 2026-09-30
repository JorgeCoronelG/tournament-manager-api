<?php

namespace App\Core\Enum;

enum MatchEventType: string
{
    case GOAL = 'goal';
    case OWN_GOAL = 'own_goal';
    case YELLOW_CARD = 'yellow_card';
    case RED_CARD = 'red_card';
    case SUBSTITUTION = 'substitution';

    public function label(): string
    {
        return match ($this) {
            self::GOAL => 'Gol',
            self::OWN_GOAL => 'Autogol',
            self::YELLOW_CARD => 'Tarjeta amarilla',
            self::RED_CARD => 'Tarjeta roja',
            self::SUBSTITUTION => 'Sustitución',
        };
    }
}
