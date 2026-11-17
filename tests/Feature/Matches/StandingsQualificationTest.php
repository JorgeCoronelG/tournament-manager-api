<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class StandingsQualificationTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function base(League $league, Tournament $tournament): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    /**
     * Torneo en curso con un grupo regular por cada tamaño, calendario generado y todo jugado: gana la inscripción más antigua.
     *
     * @param  list<int>  $sizes
     * @param  (callable(int, int): array{0: int, 1: int})|null  $score  Orden de inscripción del local y del visitante → goles.
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: list<TournamentGroup>}
     */
    private function played(array $sizes, ?callable $score = null): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $tournament->groups()->delete();
        $groups = [];

        foreach ($sizes as $index => $size) {
            $groups[] = $group = $this->makeGroup($tournament, 'Grupo '.chr(65 + $index));
            $this->teamsIn($tournament, $group, $size);
        }

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => 1])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();
        $rank = array_flip($tournament->registrations()->orderBy('id')->pluck('id')->all());

        foreach (GameMatch::query()->where('tournament_id', $tournament->id)->get() as $match) {
            [$home, $away] = [$rank[$match->home_registration_id], $rank[$match->away_registration_id]];
            [$homeGoals, $awayGoals] = $score !== null ? $score($home, $away) : $this->bySeniority($home, $away);
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeGoals, 'away_score' => $awayGoals])->save();
        }

        return [$league, $tournament, $header, $groups];
    }

    /**
     * Gana la inscripción más antigua; el margen crece con el grupo para que los mejores de una posición no empaten.
     *
     * @return array{0: int, 1: int}
     */
    private function bySeniority(int $home, int $away): array
    {
        $margin = 1 + intdiv(max($home, $away), 5);

        return $home < $away ? [$margin, 0] : [0, $margin];
    }

    /**
     * Grupo de 4 con un empate total entre dos equipos: `$tied` son los órdenes de inscripción (0 a 3) de los empatados,
     * que empatan 0-0 entre sí; los de mejor orden ganan 1-0 a los demás.
     *
     * @param  array{0: int, 1: int}  $tied
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: list<TournamentGroup>}
     */
    private function tiedGroup(array $tied): array
    {
        return $this->played([4], fn (int $home, int $away): array => in_array($home, $tied, true) && in_array($away, $tied, true)
            ? [0, 0]
            : ($home < $away ? [1, 0] : [0, 1]));
    }

    /**
     * @return array<int, array<string, mixed>> Filas del primer grupo por orden de inscripción.
     */
    private function rowsByRegistration(League $league, Tournament $tournament, array $header): array
    {
        $order = $tournament->registrations()->orderBy('id')->pluck('id')->all();
        $rows = collect($this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->json('groups.0.rows'))->keyBy('registration_id');

        return array_map(fn (int $id): array => $rows[$id], $order);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function playoff(League $league, Tournament $tournament, array $header, string $name, array $sources, array $legs): int
    {
        return $this->postJson($this->groupsUrl($league, $tournament), ['type' => 'playoff', 'name' => $name, 'round_legs' => $legs, 'sources' => $sources], $header)
            ->assertCreated()->json('id');
    }

    public function test_each_row_carries_the_playoff_of_its_range_and_the_legend_is_ordered_by_priority(): void
    {
        [$league, $tournament, $header, $groups] = $this->played([8]);
        $ranges = [[3, 4], [1, 2], [7, 8], [5, 6]];
        $ids = [];

        foreach ($ranges as [$from, $to]) {
            $ids["{$from}-{$to}"] = $this->playoff($league, $tournament, $header, "Liguilla {$from}-{$to}", [['source_group_id' => $groups[0]->id, 'position_start' => $from, 'position_end' => $to]], [1]);
        }

        $response = $this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk();

        $this->assertSame(['1-2', '3-4', '5-6', '7-8'], $response->json('playoffs.*.positions'));
        $this->assertSame([1, 2, 3, 4], $response->json('playoffs.*.priority'));
        $this->assertSame([$ids['1-2'], $ids['3-4'], $ids['5-6'], $ids['7-8']], $response->json('playoffs.*.id'));

        foreach ($response->json('groups.0.rows') as $row) {
            $expected = $ids[['1-2', '1-2', '3-4', '3-4', '5-6', '5-6', '7-8', '7-8'][$row['position'] - 1]];
            $this->assertSame($expected, $row['playoff']['id']);
        }

        $this->assertSame(['id', 'name', 'priority'], array_keys($response->json('groups.0.rows.0.playoff')));
    }

    public function test_only_the_best_n_of_a_position_are_marked_and_it_matches_the_close_preview(): void
    {
        [$league, $tournament, $header, $groups] = $this->played([5, 5, 5, 5, 5]);
        $sources = [
            ...array_map(fn (TournamentGroup $group) => ['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 3], $groups),
            ['kind' => 'best_of_position', 'position_start' => 4, 'best_count' => 1],
        ];
        $playoffId = $this->playoff($league, $tournament, $header, 'Liguilla', $sources, [1, 1, 1, 1]);

        $response = $this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk();
        $marked = [];

        foreach ($response->json('groups') as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['playoff'] !== null) {
                    $this->assertSame($playoffId, $row['playoff']['id']);
                    $marked[] = $row['registration_id'];
                }
            }
        }

        $preview = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk();
        $expected = array_column($preview->json('playoffs.0.qualified'), 'registration_id');

        $this->assertCount(16, $marked);
        $this->assertEqualsCanonicalizing($expected, $marked);
        $this->assertSame('1-3, Mejor 4.º', $response->json('playoffs.0.positions'));
    }

    public function test_without_playoffs_every_row_has_a_null_playoff_and_the_legend_is_empty(): void
    {
        [$league, $tournament, $header] = $this->played([4]);

        $response = $this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->assertJsonPath('playoffs', []);

        foreach ($response->json('groups.0.rows') as $row) {
            $this->assertArrayHasKey('playoff', $row);
            $this->assertNull($row['playoff']);
        }
    }

    public function test_a_tie_that_crosses_the_cut_leaves_its_teams_undefined_until_a_manual_order_is_saved(): void
    {
        [$league, $tournament, $header, $groups] = $this->tiedGroup([1, 2]);
        $playoffId = $this->playoff($league, $tournament, $header, 'Liguilla', [['source_group_id' => $groups[0]->id, 'position_start' => 1, 'position_end' => 2]], [1]);

        $rows = $this->rowsByRegistration($league, $tournament, $header);
        $this->assertSame($playoffId, $rows[0]['playoff']['id']);
        $this->assertFalse($rows[0]['playoff_pending']);

        foreach ([1, 2] as $index) {
            $this->assertNull($rows[$index]['playoff']);
            $this->assertTrue($rows[$index]['playoff_pending']);
        }

        $this->assertNull($rows[3]['playoff']);
        $this->assertFalse($rows[3]['playoff_pending']);

        $this->putJson($this->base($league, $tournament).'/standings/manual-order', ['group_id' => $groups[0]->id, 'registration_ids' => [$rows[1]['registration_id'], $rows[2]['registration_id']]], $header)->assertOk();

        $rows = $this->rowsByRegistration($league, $tournament, $header);
        $this->assertSame($playoffId, $rows[1]['playoff']['id']);
        $this->assertNull($rows[2]['playoff']);
        $this->assertFalse($rows[1]['playoff_pending']);
        $this->assertFalse($rows[2]['playoff_pending']);
    }

    public function test_a_tie_inside_the_same_playoff_is_not_pending(): void
    {
        [$league, $tournament, $header, $groups] = $this->tiedGroup([0, 1]);
        $playoffId = $this->playoff($league, $tournament, $header, 'Liguilla', [['source_group_id' => $groups[0]->id, 'position_start' => 1, 'position_end' => 2]], [1]);

        $rows = $this->rowsByRegistration($league, $tournament, $header);

        foreach ([0, 1] as $index) {
            $this->assertSame($playoffId, $rows[$index]['playoff']['id']);
            $this->assertFalse($rows[$index]['playoff_pending']);
        }
    }

    public function test_a_tie_between_two_playoffs_is_pending(): void
    {
        [$league, $tournament, $header, $groups] = $this->tiedGroup([1, 2]);

        foreach ([[1, 2], [3, 4]] as [$from, $to]) {
            $this->playoff($league, $tournament, $header, "Liguilla {$from}-{$to}", [['source_group_id' => $groups[0]->id, 'position_start' => $from, 'position_end' => $to]], [1]);
        }

        $rows = $this->rowsByRegistration($league, $tournament, $header);

        foreach ([1, 2] as $index) {
            $this->assertNull($rows[$index]['playoff']);
            $this->assertTrue($rows[$index]['playoff_pending']);
        }

        foreach ([0, 3] as $index) {
            $this->assertNotNull($rows[$index]['playoff']);
            $this->assertFalse($rows[$index]['playoff_pending']);
        }
    }

    public function test_nothing_is_pending_while_the_group_has_matches_left(): void
    {
        [$league, $tournament, $header, $groups] = $this->tiedGroup([1, 2]);
        $this->playoff($league, $tournament, $header, 'Liguilla', [['source_group_id' => $groups[0]->id, 'position_start' => 1, 'position_end' => 2]], [1]);
        GameMatch::query()->where('tournament_id', $tournament->id)->first()?->forceFill(['status' => MatchStatus::SCHEDULED, 'home_score' => null, 'away_score' => null])->save();

        foreach ($this->rowsByRegistration($league, $tournament, $header) as $row) {
            $this->assertFalse($row['playoff_pending']);
        }
    }

    public function test_a_cut_of_the_best_of_a_position_inside_a_tie_leaves_those_teams_pending(): void
    {
        // Todos los 4.º tienen los mismos números: el corte del mejor 4.º cae dentro de un empate entre grupos.
        [$league, $tournament, $header, $groups] = $this->played([5, 5, 5, 5, 5], fn (int $home, int $away): array => $home < $away ? [1, 0] : [0, 1]);
        $sources = [
            ...array_map(fn (TournamentGroup $group) => ['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 3], $groups),
            ['kind' => 'best_of_position', 'position_start' => 4, 'best_count' => 1],
        ];
        $this->playoff($league, $tournament, $header, 'Liguilla', $sources, [1, 1, 1, 1]);

        $response = $this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk();
        $fourths = 0;

        foreach ($response->json('groups') as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['position'] === 4) {
                    $fourths++;
                    $this->assertNull($row['playoff']);
                    $this->assertTrue($row['playoff_pending']);
                } else {
                    $this->assertFalse($row['playoff_pending']);
                }
            }
        }

        $this->assertSame(5, $fourths);
    }

    public function test_has_manual_order_reflects_the_saved_order_per_group_and_resolves_the_pending_tie(): void
    {
        // Grupo A con empate total entre el 2.º y el 3.º y grupo B sin empates.
        [$league, $tournament, $header, $groups] = $this->played([4, 4], fn (int $home, int $away): array => ($home % 4 === 1 || $home % 4 === 2) && ($away % 4 === 1 || $away % 4 === 2)
            ? [0, 0]
            : (($home % 4) < ($away % 4) ? [1, 0] : [0, 1]));
        $url = $this->base($league, $tournament).'/standings';
        $flags = fn (): array => array_column($this->getJson($url, $header)->assertOk()->json('groups'), 'has_manual_order');

        $this->assertSame([false, false], $flags());

        $tied = $this->getJson($url, $header)->json('groups.0.ties.0');
        $this->assertCount(2, $tied);
        $this->putJson("{$url}/manual-order", ['group_id' => $groups[0]->id, 'registration_ids' => $tied], $header)->assertOk()
            ->assertJsonPath('groups.0.has_manual_order', true);

        $reloaded = $this->getJson($url, $header)->assertOk();
        $this->assertSame([true, false], $reloaded->json('groups.*.has_manual_order'));
        $this->assertFalse($reloaded->json('groups.0.ties_pending'));

        $this->deleteJson("{$url}/manual-order?group_id={$groups[0]->id}", [], $header)->assertNoContent();
        $this->assertSame([false, false], $flags());
        $this->assertTrue($this->getJson($url, $header)->json('groups.0.ties_pending'));
    }
}
