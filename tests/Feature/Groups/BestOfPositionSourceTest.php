<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\PlayoffSource;
use App\Models\PlayoffTie;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BestOfPositionSourceTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function base(League $league, Tournament $tournament): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    /**
     * Torneo en planeación con un grupo regular por cada tamaño recibido (sin el grupo automático).
     *
     * @param  list<int>  $sizes
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: list<TournamentGroup>, 4: list<Registration>}
     */
    private function groupsOfTeams(array $sizes = [5, 5, 5, 5, 5]): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $tournament->groups()->delete();
        $groups = [];
        $registrations = [];

        foreach ($sizes as $index => $size) {
            $groups[] = $group = $this->makeGroup($tournament, 'Grupo '.chr(65 + $index));
            array_push($registrations, ...$this->teamsIn($tournament, $group, $size));
        }

        return [$league, $tournament, $header, $groups, $registrations];
    }

    /**
     * Fuentes: las posiciones 1 a 3 de cada grupo más el mejor 4.º.
     *
     * @param  list<TournamentGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private function sources(array $groups, int $bestCount = 1, int $rangeEnd = 3, int $position = 4): array
    {
        return [
            ...array_map(fn (TournamentGroup $group) => ['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => $rangeEnd], $groups),
            ['kind' => 'best_of_position', 'position_start' => $position, 'best_count' => $bestCount],
        ];
    }

    /**
     * Pone el torneo en curso, genera el calendario y juega todos los partidos con `$score(ordenLocal, ordenVisitante)`
     * (orden de inscripción en el torneo; 0 = más antigua).
     *
     * @param  callable(int, int): array{0: int, 1: int}|null  $score
     */
    private function play(League $league, Tournament $tournament, array $header, ?callable $score = null): void
    {
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => 1])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();
        $rank = array_flip($tournament->registrations()->orderBy('id')->pluck('id')->all());
        $score ??= fn (int $home, int $away) => $home < $away ? [2, 0] : [0, 2];

        foreach (GameMatch::query()->where('tournament_id', $tournament->id)->get() as $match) {
            [$h, $a] = $score($rank[$match->home_registration_id], $rank[$match->away_registration_id]);
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $h, 'away_score' => $a])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function createPlayoff(League $league, Tournament $tournament, array $header, array $sources, string $name = 'Liguilla'): int
    {
        return $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => $name, 'round_legs' => [1, 1, 1, 1], 'sources' => $sources,
        ], $header)->assertCreated()->json('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(TestResponse $response): array
    {
        return $response->assertStatus(422)->json('error');
    }

    public function test_creates_a_playoff_of_16_with_15_by_range_and_the_best_fourth(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();

        $response = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'sources' => $this->sources($groups),
        ], $header)->assertCreated()
            ->assertJsonPath('total_teams', 16)
            ->assertJsonPath('rounds', 4)
            ->assertJsonPath('round_names', ['Octavos de final', 'Cuartos de final', 'Semifinal', 'Final']);

        $best = $response->json('sources.5');
        $this->assertSame('best_of_position', $best['kind']);
        $this->assertNull($best['source_group_id']);
        $this->assertNull($best['source_group_name']);
        $this->assertSame([4, 4, 4, 1], [$best['position'], $best['position_start'], $best['position_end'], $best['best_count']]);
        $this->assertSame('range', $response->json('sources.0.kind'));
        $this->assertNull($response->json('sources.0.best_count'));
        $this->assertDatabaseHas('playoff_sources', ['kind' => 'best_of_position', 'source_group_id' => null, 'best_count' => 1, 'position_start' => 4, 'position_end' => 4]);
    }

    public function test_validates_the_best_of_source(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $url = $this->groupsUrl($league, $tournament);
        $body = fn (array $sources) => ['type' => 'playoff', 'name' => 'Liguilla', 'sources' => $sources];

        // a) los mejores: al menos 1 y menos que los candidatos (5).
        foreach ([0, 5, 6] as $count) {
            $this->assertSame(Message::PLAYOFF_BEST_COUNT, $this->errors($this->postJson($url, $body($this->sources($groups, $count)), $header))['sources.5.best_count'][0]);
        }
        $this->postJson($url, $body([...array_slice($this->sources($groups), 0, 5), ['kind' => 'best_of_position', 'position_start' => 4]]), $header)->assertStatus(422);

        // b) la posición debe existir en todos los candidatos.
        $this->assertSame(Message::PLAYOFF_BEST_POSITION, $this->errors($this->postJson($url, $body($this->sources($groups, 1, 3, 6)), $header))['sources.5.position_start'][0]);

        // c) dos fuentes de mejores de la misma posición.
        $twice = [...$this->sources($groups), ['kind' => 'best_of_position', 'position_start' => 4, 'best_count' => 1]];
        $this->assertSame(Message::PLAYOFF_BEST_DUPLICATE, $this->errors($this->postJson($url, $body($twice), $header))['sources.6.position_start'][0]);

        // d) el total debe ser potencia de 2: 15 + 2 = 17.
        $this->assertSame(Message::getMessagePlayoffTotalTeams(17), $this->errors($this->postJson($url, $body($this->sources($groups, 2)), $header))['sources'][0]);

        // Tipo desconocido.
        $this->postJson($url, $body([['kind' => 'otra', 'source_group_id' => $groups[0]->id, 'position_start' => 1, 'position_end' => 2]]), $header)->assertStatus(422);
    }

    public function test_the_position_must_exist_in_every_candidate_group(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams([5, 5, 5, 5, 3]);

        $this->assertSame(
            Message::PLAYOFF_BEST_POSITION,
            $this->errors($this->postJson($this->groupsUrl($league, $tournament), ['type' => 'playoff', 'name' => 'L', 'sources' => $this->sources($groups)], $header))['sources.5.position_start'][0]
        );
    }

    public function test_a_range_covering_the_position_removes_that_group_from_the_candidates(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams([5, 5, 3]);
        $url = $this->groupsUrl($league, $tournament);

        // El grupo C (3 equipos) cubre con su rango hasta el 3.º: el 3.º se compara solo entre A y B.
        $this->postJson($url, ['type' => 'playoff', 'name' => 'L', 'sources' => [
            ['source_group_id' => $groups[0]->id, 'position_start' => 1, 'position_end' => 2],
            ['source_group_id' => $groups[1]->id, 'position_start' => 1, 'position_end' => 2],
            ['source_group_id' => $groups[2]->id, 'position_start' => 1, 'position_end' => 3],
            ['kind' => 'best_of_position', 'position_start' => 3, 'best_count' => 1],
        ]], $header)->assertCreated()->assertJsonPath('total_teams', 8);
    }

    public function test_a_candidate_cannot_belong_to_two_playoffs_and_each_keeps_its_own_rules(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams([5, 5, 5, 5, 5, 5, 5, 5]);
        $url = $this->groupsUrl($league, $tournament);
        $first = array_slice($groups, 0, 4);

        $this->postJson($url, ['type' => 'playoff', 'name' => 'Oro', 'sources' => [
            ...array_map(fn ($g) => ['source_group_id' => $g->id, 'position_start' => 1, 'position_end' => 1], $first),
            ['kind' => 'best_of_position', 'position_start' => 2, 'best_count' => 2],
        ]], $header)->assertStatus(422);

        $this->postJson($url, ['type' => 'playoff', 'name' => 'Oro', 'sources' => [
            ...array_map(fn ($g) => ['source_group_id' => $g->id, 'position_start' => 1, 'position_end' => 2], $first),
            ['kind' => 'best_of_position', 'position_start' => 3, 'best_count' => 1],
        ]], $header)->assertStatus(422);
        $this->postJson($url, ['type' => 'playoff', 'name' => 'Oro', 'sources' => $this->sources(array_slice($groups, 0, 5), 1)], $header)->assertCreated();

        // Otra liguilla con el mejor 4.º de las mismas posiciones pelearía por los mismos equipos.
        $this->postJson($url, ['type' => 'playoff', 'name' => 'Plata', 'sources' => [
            ['source_group_id' => $groups[5]->id, 'position_start' => 1, 'position_end' => 1],
            ['source_group_id' => $groups[6]->id, 'position_start' => 1, 'position_end' => 1],
            ['source_group_id' => $groups[7]->id, 'position_start' => 1, 'position_end' => 1],
            ['kind' => 'best_of_position', 'position_start' => 4, 'best_count' => 1],
        ]], $header)->assertStatus(422);
    }

    public function test_starting_validates_the_position_exists_in_the_candidates(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        $url = $this->base($league, $tournament).'/status';

        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_preview_shows_the_comparison_and_marks_the_qualified_best_fourth(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        // El 4.º del último grupo gana su partido con más goles: es el mejor 4.º.
        $this->play($league, $tournament, $header, function (int $home, int $away): array {
            $lower = min($home, $away);
            $margin = ($lower % 5 === 3 && max($home, $away) % 5 === 4) ? intdiv($lower, 5) + 2 : 2;

            return $home < $away ? [$margin, 0] : [0, $margin];
        });

        $response = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->assertJsonPath('blockers', []);
        $best = $response->json('playoffs.0.best_of.0');

        $this->assertSame([4, 1], [$best['position'], $best['best_count']]);
        $this->assertCount(5, $best['table']);
        $this->assertSame(['rank', 'team_id', 'team_name', 'group', 'played', 'points', 'goal_difference', 'goals_for', 'points_per_match', 'qualified', 'tied'], array_values(array_intersect(['rank', 'team_id', 'team_name', 'group', 'played', 'points', 'goal_difference', 'goals_for', 'points_per_match', 'qualified', 'tied'], array_keys($best['table'][0]))));
        $this->assertSame('Grupo E', $best['table'][0]['group']);
        $this->assertSame([true, false, false, false, false], array_column($best['table'], 'qualified'));
        $this->assertSame([4, 4, 4, 4, 4], array_column($best['table'], 'played'));
        $this->assertEquals(0.75, $best['table'][0]['points_per_match']);

        $qualified = $response->json('playoffs.0.qualified');
        $this->assertCount(16, $qualified);
        $this->assertSame(16, $qualified[15]['seed']);
        $this->assertSame('Mejor 4.º', $qualified[15]['source_group']);
        $this->assertSame(4, $qualified[15]['position']);
        $this->assertSame($best['table'][0]['team_id'], $qualified[15]['team_id']);
    }

    public function test_a_tie_across_the_cut_blocks_the_closing_until_the_manual_order_resolves_it(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        $this->play($league, $tournament, $header);
        $previewUrl = $this->base($league, $tournament).'/close-regular-phase/preview';

        $response = $this->getJson($previewUrl, $header)->assertOk();
        $blocker = collect($response->json('blockers'))->firstWhere('scope', 'best_of_position');
        $this->assertSame('unresolved_ties', $blocker['type']);
        $this->assertSame(4, $blocker['position']);
        $this->assertCount(5, $blocker['teams']);
        // Con bloqueo la comparativa se muestra igual para poder resolverla, sin la propuesta de cruces.
        $this->assertArrayNotHasKey('qualified', $response->json('playoffs.0'));
        $this->assertSame([true, true, true, true, true], array_column($response->json('playoffs.0.best_of.0.table'), 'tied'));
        $this->assertSame([false, false, false, false, false], array_column($response->json('playoffs.0.best_of.0.table'), 'qualified'), 'Mientras el empate cruce el corte nadie clasifica.');

        $sourceId = PlayoffSource::query()->where('kind', 'best_of_position')->value('id');
        $url = $this->base($league, $tournament)."/playoff-sources/{$sourceId}/manual-order";
        $teams = array_column($response->json('playoffs.0.best_of.0.table'), 'team_id');

        // Equipos que no son candidatos o un conjunto incompleto no son un empate: se rechaza.
        $this->putJson($url, ['team_ids' => [$teams[1], 999999]], $header)->assertStatus(422)->assertJsonPath('error.team_ids.0', Message::STANDINGS_NOT_TIED);
        $this->putJson($url, ['team_ids' => [$teams[1]]], $header)->assertStatus(422);

        $ordered = [$teams[3], $teams[0], $teams[1], $teams[2], $teams[4]];
        $this->putJson($url, ['team_ids' => $ordered], $header)->assertOk()->assertJsonPath('blockers', [])
            ->assertJsonPath('playoffs.0.best_of.0.table.0.team_id', $teams[3])
            ->assertJsonPath('playoffs.0.best_of.0.table.0.qualified', true)
            ->assertJsonPath('playoffs.0.best_of.0.table.1.qualified', false);
        $resolved = $this->getJson($previewUrl, $header)->json('playoffs.0.best_of.0.table');
        $this->assertSame([true, false, false, false, false], array_column($resolved, 'qualified'));
        $this->assertSame([false, false, false, false, false], array_column($resolved, 'tied'));
        $this->assertDatabaseCount('best_position_manual_orders', 5);

        $this->getJson($previewUrl, $header)->assertJsonPath('blockers', [])->assertJsonPath('playoffs.0.qualified.15.team_id', $teams[3]);

        $this->deleteJson($url, [], $header)->assertNoContent();
        $this->assertDatabaseCount('best_position_manual_orders', 0);
        $back = $this->getJson($previewUrl, $header);
        $this->assertSame('unresolved_ties', $back->json('blockers.0.type'));
        $this->assertSame([true, true, true, true, true], array_column($back->json('playoffs.0.best_of.0.table'), 'tied'));
        $this->assertSame([false, false, false, false, false], array_column($back->json('playoffs.0.best_of.0.table'), 'qualified'));
    }

    public function test_a_tie_that_does_not_change_who_qualifies_does_not_block(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        // Solo el 4.º del grupo E sobresale; los otros cuatro empatan por debajo del corte.
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        $this->play($league, $tournament, $header, function (int $home, int $away): array {
            $lower = min($home, $away);
            $margin = ($lower % 5 === 3 && max($home, $away) % 5 === 4 && $lower >= 20) ? 6 : 2;

            return $home < $away ? [$margin, 0] : [0, $margin];
        });

        $response = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->assertJsonPath('blockers', []);
        $table = $response->json('playoffs.0.best_of.0.table');

        $this->assertTrue($table[0]['qualified']);
        $this->assertFalse($table[0]['tied']);
        $this->assertSame([false, true, true, true, true], array_column($table, 'tied'), 'Los cuatro que no clasifican quedan empatados entre sí, sin afectar el corte.');
    }

    public function test_the_comparison_is_fair_with_groups_of_different_size(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams([5, 5, 4, 4, 4]);
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        // En los grupos de 5 el 4.º gana al 5.º (3 pts en 4 partidos); en los de 4 el 4.º es el último y pierde todo.
        $this->play($league, $tournament, $header);

        $table = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->json('playoffs.0.best_of.0.table');

        $this->assertSame([4, 4, 3, 3, 3], array_column($table, 'played'));
        $this->assertEquals([0.75, 0.75, 0.0, 0.0, 0.0], array_column($table, 'points_per_match'));
        $this->assertSame(['Grupo A', 'Grupo B'], array_slice(array_column($table, 'group'), 0, 2));
    }

    public function test_closing_builds_the_bracket_with_the_best_fourth_seeded_last(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $playoffId = $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        $this->play($league, $tournament, $header, function (int $home, int $away): array {
            $lower = min($home, $away);
            $margin = ($lower % 5 === 3 && max($home, $away) % 5 === 4) ? intdiv($lower, 5) + 2 : 2;

            return $home < $away ? [$margin, 0] : [0, $margin];
        });

        $dates = ['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22'];
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => $dates]]], $header)
            ->assertOk()->assertJsonPath('status', 'playoffs');

        $this->assertSame(15, PlayoffTie::query()->count());
        $lastSeed = PlayoffTie::query()->where('round_index', 1)->get()->flatMap(fn ($tie) => [$tie->seed_a, $tie->seed_b])->max();
        $this->assertSame(16, $lastSeed);

        $bracket = $this->getJson($this->base($league, $tournament).'/playoffs', $header)->assertOk()->json('playoffs.0.rounds.0.ties');
        $rivalOfFirst = $bracket[0]['team_b'];
        $this->assertSame(1, $bracket[0]['seed_a']);
        $this->assertSame(16, $bracket[0]['seed_b']);
        $this->assertNotNull($rivalOfFirst);
    }

    public function test_manual_order_rules_for_the_best_source(): void
    {
        [$league, $tournament, $header, $groups] = $this->groupsOfTeams();
        $this->createPlayoff($league, $tournament, $header, $this->sources($groups));
        $rangeId = PlayoffSource::query()->where('kind', 'range')->value('id');
        $bestId = PlayoffSource::query()->where('kind', 'best_of_position')->value('id');

        $this->putJson($this->base($league, $tournament)."/playoff-sources/{$bestId}/manual-order", ['team_ids' => [1, 2]], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS);
        $this->deleteJson($this->base($league, $tournament)."/playoff-sources/{$bestId}/manual-order", [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS);

        $this->play($league, $tournament, $header);
        $this->putJson($this->base($league, $tournament)."/playoff-sources/{$rangeId}/manual-order", ['team_ids' => [1, 2]], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::PLAYOFF_BEST_NOT_SOURCE);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function methods(): array
    {
        return ['put' => ['PUT'], 'delete' => ['DELETE']];
    }

    #[DataProvider('methods')]
    public function test_manual_order_permissions(string $method): void
    {
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        $group = $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);
        $source = new PlayoffSource;
        $source->forceFill(['playoff_group_id' => $group->id, 'kind' => 'best_of_position', 'position_start' => 4, 'position_end' => 4, 'best_count' => 1])->save();
        $url = $this->base($tournament->league, $tournament)."/playoff-sources/{$source->id}/manual-order";

        $this->json($method, $url)->assertStatus(401);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }
    }
}
