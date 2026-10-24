<?php

namespace App\Helpers;

/**
 * Cuadro de una liguilla con N equipos (potencia de 2) sembrados del 1 (mejor) al N.
 */
class PlayoffBracket
{
    /**
     * Cruces de la primera ronda en el orden del cuadro estándar, `[mejor seed, peor seed]`: con 8, 1v8, 4v5, 2v7, 3v6.
     * Los ganadores de cruces consecutivos (1-2, 3-4...) se enfrentan en la ronda siguiente. Si un cruce junta a dos equipos
     * del mismo grupo de origen, se intenta intercambiar rivales con el cruce de un seed contiguo sin que el resultado
     * tenga más conflictos (mejor esfuerzo).
     *
     * @param  array<int, int|string>  $groupBySeed  seed => grupo de origen
     * @return list<array{0: int, 1: int}>
     */
    public static function firstRound(int $teams, array $groupBySeed = []): array
    {
        /** @var list<int> $order */
        $order = [1];

        while (count($order) < $teams) {
            $size = count($order) * 2;
            $next = [];

            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $size + 1 - $seed;
            }

            $order = $next;
        }

        $pairs = [];

        for ($i = 0; $i < $teams; $i += 2) {
            $pairs[] = [$order[$i], $order[$i + 1] ?? $order[$i]];
        }

        return self::separateSameGroup($pairs, $groupBySeed);
    }

    /**
     * Cruce de la ronda siguiente al que avanza el ganador del cruce $position (desde 1) y lado que ocupa (a: impar, b: par).
     *
     * @return array{position: int, side: string}
     */
    public static function next(int $position): array
    {
        return ['position' => intdiv($position + 1, 2), 'side' => $position % 2 === 1 ? 'a' : 'b'];
    }

    /**
     * Local y visitante de un partido de un cruce. Partido único: el mejor sembrado es local. Ida y vuelta: la ida es del
     * peor sembrado y la vuelta del mejor.
     *
     * @return array{0: int, 1: int} `[local, visitante]` con los valores recibidos (ids de inscripción)
     */
    public static function sides(int $seedA, int $registrationA, int $seedB, int $registrationB, int $legs, int $leg): array
    {
        $aIsBetter = $seedA <= $seedB;
        [$better, $worse] = $aIsBetter ? [$registrationA, $registrationB] : [$registrationB, $registrationA];

        if ($legs === 1 || $leg === 2) {
            return [$better, $worse];
        }

        return [$worse, $better];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pairs
     * @param  array<int, int|string>  $groupBySeed
     * @return list<array{0: int, 1: int}>
     */
    private static function separateSameGroup(array $pairs, array $groupBySeed): array
    {
        if ($groupBySeed === []) {
            return $pairs;
        }

        $conflicts = fn (array $pair) => isset($groupBySeed[$pair[0]], $groupBySeed[$pair[1]]) && $groupBySeed[$pair[0]] === $groupBySeed[$pair[1]];

        foreach ($pairs as $i => $pair) {
            if (! $conflicts($pair)) {
                continue;
            }

            foreach ($pairs as $j => $other) {
                if ($j === $i || abs($pair[0] - $other[0]) !== 1) {
                    continue;
                }

                $swappedA = [$pair[0], $other[1]];
                $swappedB = [$other[0], $pair[1]];

                if (! $conflicts($swappedA) && ! $conflicts($swappedB)) {
                    $pairs[$i] = $swappedA;
                    $pairs[$j] = $swappedB;

                    break;
                }
            }
        }

        return $pairs;
    }
}
