<?php

namespace App\Core\Enum;

/**
 * Catálogo de criterios de desempate de la tabla de posiciones; el torneo guarda la lista ordenada.
 */
enum Tiebreaker: string
{
    case POINTS = 'points';
    case GOAL_DIFFERENCE = 'goal_difference';
    case GOALS_FOR = 'goals_for';
    case GOALS_AGAINST = 'goals_against';
    case HEAD_TO_HEAD = 'head_to_head';
    case WINS = 'wins';

    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [self::POINTS->value, self::GOAL_DIFFERENCE->value, self::GOALS_FOR->value, self::HEAD_TO_HEAD->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
