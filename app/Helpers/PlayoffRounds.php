<?php

namespace App\Helpers;

/**
 * Rondas de una liguilla según el total de equipos (potencia de 2).
 */
class PlayoffRounds
{
    public static function isValidTotal(int $total): bool
    {
        return $total >= 2 && ($total & ($total - 1)) === 0;
    }

    /**
     * Cantidad de rondas: log2 del total de equipos.
     */
    public static function count(int $total): int
    {
        return $total >= 2 ? (int) log($total, 2) : 0;
    }

    /**
     * Nombres de las rondas, de la primera a la final.
     *
     * @return list<string>
     */
    public static function names(int $total): array
    {
        $names = [];

        for ($teams = $total; $teams >= 2; $teams = intdiv($teams, 2)) {
            $names[] = match ($teams) {
                2 => 'Final',
                4 => 'Semifinal',
                8 => 'Cuartos de final',
                16 => 'Octavos de final',
                default => "Ronda de {$teams}",
            };
        }

        return $names;
    }
}
