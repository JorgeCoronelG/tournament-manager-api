<?php

namespace Tests\Unit\Helpers;

use App\Helpers\Standings;
use PHPUnit\Framework\TestCase;

class StandingsTest extends TestCase
{
    /**
     * @return array{home: int, away: int, home_score: int, away_score: int}
     */
    private function m(int $home, int $away, int $homeScore, int $awayScore): array
    {
        return ['home' => $home, 'away' => $away, 'home_score' => $homeScore, 'away_score' => $awayScore];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>}  $table
     * @return list<int>
     */
    private function order(array $table): array
    {
        return array_map(fn (array $row) => $row['registration_id'], $table['rows']);
    }

    public function test_computes_stats_and_orders_by_points_then_the_given_criteria(): void
    {
        $table = Standings::build([1, 2, 3], [$this->m(1, 2, 3, 0), $this->m(2, 3, 1, 1), $this->m(3, 1, 0, 2)], ['points', 'goal_difference']);

        $this->assertSame([1, 3, 2], $this->order($table));
        $this->assertSame([1, 2, 3], array_column($table['rows'], 'position'));
        $this->assertSame(['played' => 2, 'won' => 2, 'drawn' => 0, 'lost' => 0, 'goals_for' => 5, 'goals_against' => 0, 'goal_difference' => 5, 'points' => 6], array_intersect_key($table['rows'][0], array_flip(['played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference', 'points'])));
        $this->assertSame([], $table['ties']);
    }

    public function test_points_do_not_have_to_go_first(): void
    {
        // 1 gana 1-0 (3 pts, DG +1); 2 pierde con DG -1 pero... el criterio es solo goals_for.
        $table = Standings::build([1, 2], [$this->m(1, 2, 1, 0)], ['goals_against']);

        $this->assertSame([1, 2], $this->order($table));
    }

    public function test_goals_against_lower_is_better_and_wins_count(): void
    {
        $matches = [$this->m(1, 3, 0, 0), $this->m(2, 3, 0, 0)];

        $this->assertSame([1, 2, 3], $this->order(Standings::build([1, 2, 3], [$this->m(1, 2, 0, 0), $this->m(1, 3, 1, 1), $this->m(2, 3, 4, 4)], ['goals_against'])));

        $byWins = Standings::build([1, 2, 3], [$this->m(1, 2, 1, 0), $this->m(2, 3, 5, 0), $this->m(3, 1, 1, 0)], ['wins', 'goal_difference']);
        $this->assertSame(2, $byWins['rows'][0]['registration_id']);
        $this->assertNotEmpty($matches);
    }

    public function test_head_to_head_only_applies_to_teams_still_tied(): void
    {
        // 1, 2 y 3 empatan a 3 puntos. Entre ellos: 2 le ganó a 1, 1 a 3, 3 a 2 -> mini-tabla empatada; pasa a la siguiente.
        $matches = [$this->m(2, 1, 1, 0), $this->m(1, 3, 1, 0), $this->m(3, 2, 1, 0), $this->m(4, 1, 5, 0), $this->m(4, 2, 5, 0), $this->m(4, 3, 1, 0)];

        $table = Standings::build([1, 2, 3, 4], $matches, ['points', 'head_to_head', 'goals_against']);

        $this->assertSame(4, $table['rows'][0]['registration_id']);
        $this->assertSame([3, 1, 2], array_slice($this->order($table), 1));
    }

    public function test_head_to_head_between_two_teams_uses_their_direct_result(): void
    {
        // 1 y 2 empatan en puntos con 3 pts; 2 le ganó a 1 directamente.
        $matches = [$this->m(2, 1, 1, 0), $this->m(1, 3, 4, 0), $this->m(3, 2, 1, 0)];

        $this->assertSame([2, 1, 3], $this->order(Standings::build([1, 2, 3], [$matches[0], $matches[1], $this->m(2, 3, 1, 1)], ['points', 'head_to_head'])));
    }

    public function test_unresolved_ties_share_position_and_are_listed(): void
    {
        $table = Standings::build([1, 2, 3, 4], [$this->m(1, 2, 1, 1), $this->m(3, 4, 1, 1)], ['points', 'head_to_head']);

        $this->assertSame([[1, 2, 3, 4]], $table['ties']);
        $this->assertSame([1, 1, 1, 1], array_column($table['rows'], 'position'));
        $this->assertSame([true, true, true, true], array_column($table['rows'], 'tied'));
    }

    public function test_without_played_matches_keeps_registration_order_with_consecutive_positions(): void
    {
        $table = Standings::build([7, 3, 9], [], ['points', 'head_to_head'], [7 => 1, 3 => 2, 9 => 3]);

        $this->assertSame([7, 3, 9], $this->order($table));
        $this->assertSame([1, 2, 3], array_column($table['rows'], 'position'));
        $this->assertSame([false, false, false], array_column($table['rows'], 'tied'));
        $this->assertSame([], $table['ties']);
    }

    public function test_competition_ranking_after_a_tie(): void
    {
        $table = Standings::build([1, 2, 3], [$this->m(1, 2, 0, 0), $this->m(1, 3, 2, 0), $this->m(2, 3, 2, 0)], ['points']);

        $this->assertSame([1, 1, 3], array_column($table['rows'], 'position'));
        $this->assertSame([[1, 2]], $table['ties']);
    }

    public function test_manual_order_resolves_a_tie_only_when_it_covers_exactly_that_set(): void
    {
        $matches = [$this->m(1, 2, 0, 0)];

        $resolved = Standings::build([1, 2], $matches, ['points'], [2 => 1, 1 => 2]);
        $this->assertSame([2, 1], $this->order($resolved));
        $this->assertSame([1, 2], array_column($resolved['rows'], 'position'));
        $this->assertSame([], $resolved['ties']);

        $partial = Standings::build([1, 2], $matches, ['points'], [2 => 1]);
        $this->assertSame([[1, 2]], $partial['ties']);

        $stale = Standings::build([1, 2], $matches, ['points'], [1 => 2, 2 => 3]);
        $this->assertSame([[1, 2]], $stale['ties']);
    }
}
