<?php

namespace Tests\Unit\Helpers;

use App\Helpers\BestOfPosition;
use PHPUnit\Framework\TestCase;

class BestOfPositionTest extends TestCase
{
    /**
     * @return array<string, int>
     */
    private function c(int $id, int $played, int $points, int $goalDifference, int $goalsFor): array
    {
        return ['registration_id' => $id, 'played' => $played, 'points' => $points, 'goal_difference' => $goalDifference, 'goals_for' => $goalsFor];
    }

    /**
     * @param  array{ordered: list<array<string, mixed>>}  $ranked
     * @return list<int>
     */
    private function ids(array $ranked): array
    {
        return array_map(fn (array $row) => $row['registration_id'], $ranked['ordered']);
    }

    public function test_candidates_exclude_groups_whose_position_is_covered_by_a_range(): void
    {
        $ranges = [
            ['source_group_id' => 1, 'position_start' => 1, 'position_end' => 3],
            ['source_group_id' => 2, 'position_start' => 1, 'position_end' => 4],
            ['source_group_id' => 3, 'position_start' => 1, 'position_end' => 2],
        ];

        $this->assertSame([1, 3, 4], BestOfPosition::candidateGroupIds(4, $ranges, [1, 3, 4]), 'El grupo 2 cubre la posición 4; los demás no.');
        $this->assertSame([3, 4], BestOfPosition::candidateGroupIds(3, $ranges, [1, 3, 4]));
        $this->assertSame([4], BestOfPosition::candidateGroupIds(1, $ranges, [1, 3, 4]));
    }

    public function test_compares_per_match_played_so_groups_of_different_size_are_fair(): void
    {
        // 6 puntos en 4 partidos (1.5) contra 5 puntos en 3 (1.67): gana el segundo aunque tenga menos puntos totales.
        $ranked = BestOfPosition::rank([$this->c(1, 4, 6, 0, 4), $this->c(2, 3, 5, 0, 4)], 1);

        $this->assertSame([2, 1], $this->ids($ranked));
        $this->assertSame([], $ranked['ties']);
    }

    public function test_equal_rates_with_different_played_are_tied_and_a_tie_across_the_cut_blocks(): void
    {
        // 3 pts / 3 partidos = 6 pts / 6 partidos, e iguales en diferencia y goles por partido.
        $ranked = BestOfPosition::rank([$this->c(1, 3, 3, 1, 2), $this->c(2, 6, 6, 2, 4), $this->c(3, 4, 1, -3, 1)], 1);

        $this->assertSame([[1, 2]], $ranked['ties']);
        $this->assertSame([[1, 2]], $ranked['blocking']);
    }

    public function test_a_tie_that_does_not_affect_who_qualifies_does_not_block(): void
    {
        // Clasifican 2; los empatados son el 3.º y el 4.º.
        $ranked = BestOfPosition::rank([$this->c(1, 4, 9, 5, 8), $this->c(2, 4, 7, 3, 6), $this->c(3, 4, 2, 0, 2), $this->c(4, 4, 2, 0, 2)], 2);

        $this->assertSame([[3, 4]], $ranked['ties']);
        $this->assertSame([], $ranked['blocking']);

        // Y un empate dentro de los clasificados tampoco bloquea.
        $inside = BestOfPosition::rank([$this->c(1, 4, 9, 5, 8), $this->c(2, 4, 9, 5, 8), $this->c(3, 4, 2, 0, 2)], 2);
        $this->assertSame([], $inside['blocking']);
    }

    public function test_manual_order_resolves_the_tie_only_when_it_covers_all_the_tied_teams(): void
    {
        $candidates = [$this->c(1, 4, 3, 0, 2), $this->c(2, 4, 3, 0, 2), $this->c(3, 4, 0, -5, 0)];

        $resolved = BestOfPosition::rank($candidates, 1, [2 => 1, 1 => 2]);
        $this->assertSame([2, 1, 3], $this->ids($resolved));
        $this->assertSame([], $resolved['blocking']);

        $partial = BestOfPosition::rank($candidates, 1, [2 => 1]);
        $this->assertSame([[1, 2]], $partial['blocking']);

        $stale = BestOfPosition::rank($candidates, 1, [1 => 2, 2 => 3]);
        $this->assertSame([[1, 2]], $stale['blocking']);
    }

    public function test_without_played_matches_the_rate_is_zero(): void
    {
        $ranked = BestOfPosition::rank([$this->c(1, 0, 0, 0, 0), $this->c(2, 3, 1, 0, 1)], 1);

        $this->assertSame([2, 1], $this->ids($ranked));
    }
}
