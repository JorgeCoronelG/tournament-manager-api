<?php

namespace App\Helpers;

/**
 * Puntos por resultado en la tabla de posiciones. El descanso no suma puntos ni cuenta como partido jugado.
 */
class ScoringRules
{
    public const WIN_POINTS = 3;

    public const DRAW_POINTS = 1;

    public const LOSS_POINTS = 0;
}
