<?php

namespace Tests\Unit\Helpers;

use App\Helpers\RoundRobin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoundRobinTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function sizes(): array
    {
        return ['2' => [2, 1], '3' => [3, 1], '4' => [4, 1], '5' => [5, 2], '6' => [6, 2], '7' => [7, 1], '8' => [8, 2], '16' => [16, 2]];
    }

    #[DataProvider('sizes')]
    public function test_everyone_plays_everyone_once_per_round_with_balanced_home_and_away(int $teams, int $rounds): void
    {
        $matchdays = RoundRobin::matchdays(range(1, $teams), $rounds);
        $perRound = RoundRobin::matchdaysPerRound($teams);

        $this->assertCount($perRound * $rounds, $matchdays);

        $pairs = [];
        $balance = array_fill(1, $teams, 0);

        foreach ($matchdays as $matches) {
            $this->assertCount(intdiv($teams, 2), $matches);
            $playing = [];

            foreach ($matches as [$home, $away]) {
                $playing[] = $home;
                $playing[] = $away;
                $key = min($home, $away).'-'.max($home, $away);
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                $balance[$home]++;
                $balance[$away]--;
            }

            $this->assertSame($playing, array_unique($playing), 'Un equipo juega dos veces en la misma jornada.');
        }

        $this->assertCount($teams * ($teams - 1) / 2, $pairs);
        $this->assertSame([$rounds], array_values(array_unique($pairs)));

        foreach ($balance as $difference) {
            $this->assertLessThanOrEqual($rounds === 2 ? 0 : 1, abs($difference));
        }
    }

    public function test_second_round_repeats_the_first_with_inverted_sides(): void
    {
        $days = RoundRobin::matchdays([10, 20, 30, 40], 2);

        $this->assertSame(array_map(fn (array $p) => [$p[1], $p[0]], $days[0]), $days[3]);
    }

    public function test_fewer_than_two_teams_have_no_matches(): void
    {
        $this->assertSame([], RoundRobin::matchdays([1]));
        $this->assertSame(0, RoundRobin::matchdaysPerRound(1));
    }
}
