<?php

namespace App\Helpers;

use App\Core\Enum\Tiebreaker;

/**
 * Tabla de posiciones de un grupo: estadísticas, orden por los criterios del torneo (en el orden dado), orden manual
 * para los empates que persisten y empates sin resolver. Sin partidos jugados, orden de inscripción sin empates.
 */
class Standings
{
    /**
     * @param  list<int>  $registrationIds
     * @param  list<array{home: int, away: int, home_score: int, away_score: int}>  $matches  partidos jugados del grupo
     * @param  list<string>  $criteria  valores de Tiebreaker, en orden
     * @param  array<int, int>  $manualOrder  registration_id => posición guardada
     * @return array{rows: list<array<string, mixed>>, ties: list<list<int>>}
     */
    public static function build(array $registrationIds, array $matches, array $criteria, array $manualOrder = []): array
    {
        $stats = self::stats($registrationIds, $matches);

        // Sin partidos jugados no hay nada que ordenar ni empates: se respeta el orden de inscripción.
        if ($matches === []) {
            return [
                'rows' => array_map(
                    fn (int $id, int $index) => ['position' => $index + 1, 'registration_id' => $id] + $stats[$id] + ['tied' => false],
                    $registrationIds,
                    array_keys($registrationIds)
                ),
                'ties' => [],
            ];
        }

        $groups = [$registrationIds];

        foreach ($criteria as $criterion) {
            $next = [];

            foreach ($groups as $group) {
                array_push($next, ...(count($group) > 1 ? self::split($group, $criterion, $stats, $matches) : [$group]));
            }

            $groups = $next;
        }

        $groups = array_map(fn (array $group) => self::applyManual($group, $manualOrder), $groups);

        $rows = [];
        $ties = [];
        $position = 1;

        foreach ($groups as $group) {
            $resolved = $group['resolved'];
            $members = $group['ids'];

            if (count($members) > 1 && ! $resolved) {
                $ties[] = $members;
            }

            foreach ($members as $index => $id) {
                $rows[] = ['position' => $resolved ? $position + $index : $position, 'registration_id' => $id] + $stats[$id]
                    + ['tied' => count($members) > 1 && ! $resolved];
            }

            $position += count($members);
        }

        return ['rows' => $rows, 'ties' => $ties];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<array{home: int, away: int, home_score: int, away_score: int}>  $matches
     * @return array<int, array<string, int>>
     */
    private static function stats(array $ids, array $matches): array
    {
        $stats = [];

        foreach ($ids as $id) {
            $stats[$id] = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0];
        }

        foreach ($matches as $match) {
            if (! isset($stats[$match['home']], $stats[$match['away']])) {
                continue;
            }

            self::addResult($stats[$match['home']], $match['home_score'], $match['away_score']);
            self::addResult($stats[$match['away']], $match['away_score'], $match['home_score']);
        }

        return $stats;
    }

    /**
     * @param  array<string, int>  $row
     */
    private static function addResult(array &$row, int $for, int $against): void
    {
        $row['played']++;
        $row['goals_for'] += $for;
        $row['goals_against'] += $against;
        $row['goal_difference'] = $row['goals_for'] - $row['goals_against'];

        if ($for > $against) {
            $row['won']++;
            $row['points'] += ScoringRules::WIN_POINTS;
        } elseif ($for === $against) {
            $row['drawn']++;
            $row['points'] += ScoringRules::DRAW_POINTS;
        } else {
            $row['lost']++;
            $row['points'] += ScoringRules::LOSS_POINTS;
        }
    }

    /**
     * Divide un conjunto empatado en subconjuntos ordenados (de mejor a peor) según un criterio.
     *
     * @param  list<int>  $group
     * @param  array<int, array<string, int>>  $stats
     * @param  list<array{home: int, away: int, home_score: int, away_score: int}>  $matches
     * @return list<list<int>>
     */
    private static function split(array $group, string $criterion, array $stats, array $matches): array
    {
        $keys = [];

        if ($criterion === Tiebreaker::HEAD_TO_HEAD->value) {
            $mini = self::stats($group, $matches);

            foreach ($group as $id) {
                $keys[$id] = [$mini[$id]['points'], $mini[$id]['goal_difference'], $mini[$id]['goals_for']];
            }
        } else {
            // Mayor es mejor; los goles en contra, al revés.
            $field = $criterion === Tiebreaker::WINS->value ? 'won' : $criterion;
            $sign = $criterion === Tiebreaker::GOALS_AGAINST->value ? -1 : 1;

            foreach ($group as $id) {
                $keys[$id] = [$sign * ($stats[$id][$field] ?? 0)];
            }
        }

        usort($group, fn (int $a, int $b) => $keys[$b] <=> $keys[$a]);

        $result = [];

        foreach ($group as $id) {
            $last = count($result) - 1;

            if ($last >= 0 && $keys[$result[$last][0]] === $keys[$id]) {
                $result[$last][] = $id;
            } else {
                $result[] = [$id];
            }
        }

        return $result;
    }

    /**
     * Un conjunto empatado se ordena a mano solo si todos sus equipos tienen orden guardado y sus posiciones son
     * exactamente 1..n (el conjunto guardado es este mismo).
     *
     * @param  list<int>  $group
     * @param  array<int, int>  $manualOrder
     * @return array{ids: list<int>, resolved: bool}
     */
    private static function applyManual(array $group, array $manualOrder): array
    {
        if (count($group) < 2) {
            return ['ids' => $group, 'resolved' => true];
        }

        $positions = [];

        foreach ($group as $id) {
            if (! isset($manualOrder[$id])) {
                return ['ids' => $group, 'resolved' => false];
            }

            $positions[$id] = $manualOrder[$id];
        }

        $sorted = array_values($positions);
        sort($sorted);

        if ($sorted !== range(1, count($group))) {
            return ['ids' => $group, 'resolved' => false];
        }

        asort($positions);

        return ['ids' => array_keys($positions), 'resolved' => true];
    }
}
