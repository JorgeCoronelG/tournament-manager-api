<?php

namespace App\Helpers;

/**
 * Todos contra todos por el método del círculo. Con número impar de equipos uno descansa cada jornada (no hay partido).
 * Local y visitante salen de un torneo cíclico (en pares, con un equipo fantasma), así que la diferencia local-visitante
 * de cada equipo es de 1 como máximo; la segunda vuelta invierte las localías.
 */
class RoundRobin
{
    /**
     * Partidos por jornada, `[local, visitante]` con las claves de $teams, jornadas de la 1 en adelante.
     *
     * @param  list<int>  $teams
     * @return list<list<array{0: int, 1: int}>>
     */
    public static function matchdays(array $teams, int $rounds = 1): array
    {
        $count = count($teams);

        if ($count < 2) {
            return [];
        }

        $size = $count + ($count % 2);
        $cycle = $count % 2 === 1 ? $count : $count + 1;
        $slots = range(0, $size - 1);
        $first = [];

        for ($round = 0; $round < $size - 1; $round++) {
            $matches = [];

            for ($i = 0; $i < intdiv($size, 2); $i++) {
                $a = $slots[$i];
                $b = $slots[$size - 1 - $i];

                if ($a >= $count || $b >= $count) {
                    continue;
                }

                [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];
                $homeIndex = ($high - $low) <= intdiv($cycle - 1, 2) ? $low : $high;
                $awayIndex = $homeIndex === $low ? $high : $low;
                $matches[] = [$teams[$homeIndex], $teams[$awayIndex]];
            }

            $first[] = $matches;
            $slots = [$slots[0], $slots[$size - 1], ...array_slice($slots, 1, $size - 2)];
        }

        $all = $first;

        if ($rounds >= 2) {
            foreach ($first as $matches) {
                $all[] = array_map(fn (array $pair) => [$pair[1], $pair[0]], $matches);
            }
        }

        return $all;
    }

    /**
     * Jornadas de una vuelta para $teams equipos.
     */
    public static function matchdaysPerRound(int $teams): int
    {
        return $teams < 2 ? 0 : $teams - 1 + ($teams % 2);
    }
}
