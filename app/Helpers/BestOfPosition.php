<?php

namespace App\Helpers;

/**
 * Fuente «mejores de una posición entre grupos»: candidatos y comparativa entre grupos de distinto tamaño.
 */
class BestOfPosition
{
    /**
     * Grupos candidatos: los regulares sin esa posición cubierta por una fuente de rango de la misma liguilla.
     *
     * @param  array<int, array{source_group_id: int|null, position_start: int, position_end: int}>  $ranges  fuentes de rango de la liguilla
     * @param  array<int, int>  $regularGroupIds
     * @return list<int>
     */
    public static function candidateGroupIds(int $position, array $ranges, array $regularGroupIds): array
    {
        return array_values(array_filter($regularGroupIds, function (int $groupId) use ($position, $ranges) {
            foreach ($ranges as $range) {
                if ($range['source_group_id'] === $groupId && $range['position_start'] <= $position && $position <= $range['position_end']) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Ordena a los candidatos por puntos, diferencia de goles y goles a favor por partido jugado (cálculo exacto con
     * fracciones; sin partidos, 0) y resuelve con el orden manual los empates que lo cubren por completo. Un empate que
     * cruza el corte (algunos entran entre los `$bestCount` y otros no) sin resolver bloquea el cierre.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<int, int>  $manualOrder  registration_id => sort_order
     * @return array{ordered: list<array<string, mixed>>, ties: list<list<int>>, blocking: list<list<int>>}
     */
    public static function rank(array $candidates, int $bestCount, array $manualOrder = []): array
    {
        usort($candidates, fn (array $a, array $b) => self::compare($a, $b));

        $sets = [];

        foreach ($candidates as $candidate) {
            $last = count($sets) - 1;

            if ($last >= 0 && self::compare($sets[$last][0], $candidate) === 0) {
                $sets[$last][] = $candidate;
            } else {
                $sets[] = [$candidate];
            }
        }

        $ordered = [];
        $ties = [];
        $blocking = [];

        foreach ($sets as $set) {
            $start = count($ordered);

            if (count($set) > 1) {
                $manual = self::manual($set, $manualOrder);

                if ($manual !== null) {
                    $set = $manual;
                } else {
                    $ids = array_map(fn (array $row) => self::int($row['registration_id']), $set);
                    $ties[] = $ids;

                    if ($start < $bestCount && $start + count($set) > $bestCount) {
                        $blocking[] = $ids;
                    }
                }
            }

            array_push($ordered, ...$set);
        }

        return ['ordered' => $ordered, 'ties' => $ties, 'blocking' => $blocking];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Valor por partido jugado como fracción comparable: [numerador, denominador].
     *
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: int}
     */
    private static function rate(array $row, string $field): array
    {
        $played = self::int($row['played']);

        return $played === 0 ? [0, 1] : [self::int($row[$field]), $played];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach (['points', 'goal_difference', 'goals_for'] as $field) {
            [$na, $da] = self::rate($a, $field);
            [$nb, $db] = self::rate($b, $field);
            $result = ($nb * $da) <=> ($na * $db);

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }

    /**
     * Un conjunto empatado se ordena a mano solo si todos sus equipos tienen orden guardado y sus valores son 1..n.
     *
     * @param  list<array<string, mixed>>  $set
     * @param  array<int, int>  $manualOrder
     * @return list<array<string, mixed>>|null
     */
    private static function manual(array $set, array $manualOrder): ?array
    {
        $orders = [];

        foreach ($set as $row) {
            $id = self::int($row['registration_id']);

            if (! isset($manualOrder[$id])) {
                return null;
            }

            $orders[$id] = $manualOrder[$id];
        }

        $sorted = array_values($orders);
        sort($sorted);

        if ($sorted !== range(1, count($set))) {
            return null;
        }

        usort($set, fn (array $a, array $b) => $orders[self::int($a['registration_id'])] <=> $orders[self::int($b['registration_id'])]);

        return $set;
    }
}
