<?php

namespace App\Helpers;

use App\Core\Enum\MatchPhase;

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
     * Fase de los partidos de una ronda en la que juegan $teams equipos (2 = final).
     */
    public static function phaseFor(int $teams): MatchPhase
    {
        return match (true) {
            $teams <= 2 => MatchPhase::FINAL,
            $teams === 4 => MatchPhase::SEMIFINAL,
            $teams === 8 => MatchPhase::QUARTERFINAL,
            $teams === 16 => MatchPhase::ROUND_OF_16,
            default => MatchPhase::ROUND_OF_32,
        };
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
