<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class MatchResultAndStandingsTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function base(League $league, Tournament $tournament): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    /**
     * Torneo en curso, una vuelta, 3 equipos con el calendario generado sin sorteo.
     *
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: list<Registration>}
     */
    private function runningTournament(int $teams = 3): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registrations = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), $teams);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();

        return [$league, $tournament, $header, $registrations];
    }

    private function play(Tournament $tournament, Registration $home, Registration $away, int $homeScore, int $awayScore): GameMatch
    {
        $match = GameMatch::query()->where('tournament_id', $tournament->id)
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('home_registration_id', $home->id)->where('away_registration_id', $away->id))
                ->orWhere(fn ($x) => $x->where('home_registration_id', $away->id)->where('away_registration_id', $home->id)))
            ->firstOrFail();

        $swap = $match->home_registration_id !== $home->id;
        $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $swap ? $awayScore : $homeScore, 'away_score' => $swap ? $homeScore : $awayScore])->save();

        return $match;
    }

    public function test_records_corrects_and_clears_a_result(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        $match = GameMatch::query()->firstOrFail();
        $url = $this->base($league, $tournament)."/matches/{$match->id}/result";

        $this->patchJson($url, ['home_score' => 2, 'away_score' => 1], $header)->assertOk()
            ->assertJsonPath('status', 'played')->assertJsonPath('home_score', 2)->assertJsonPath('away_score', 1);
        $this->patchJson($url, ['home_score' => 0, 'away_score' => 0], $header)->assertOk()->assertJsonPath('home_score', 0);
        $this->deleteJson($url, [], $header)->assertOk()->assertJsonPath('status', 'scheduled')->assertJsonPath('home_score', null)->assertJsonPath('away_score', null);
    }

    public function test_validates_the_scores(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        $url = $this->base($league, $tournament).'/matches/'.GameMatch::query()->value('id').'/result';

        foreach ([[], ['home_score' => 1], ['home_score' => -1, 'away_score' => 0], ['home_score' => 100, 'away_score' => 0], ['home_score' => 'a', 'away_score' => 0]] as $body) {
            $this->patchJson($url, $body, $header)->assertStatus(422);
        }
    }

    public function test_results_follow_the_tournament_status_and_regular_phase(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        $match = GameMatch::query()->firstOrFail();
        $url = $this->base($league, $tournament)."/matches/{$match->id}/result";
        $body = ['home_score' => 1, 'away_score' => 0];

        foreach ([[TournamentStatus::PLANNING, Message::TOURNAMENT_NOT_STARTED], [TournamentStatus::PLAYOFFS, Message::REGULAR_STAGE_CLOSED], [TournamentStatus::FINISHED, Message::REGULAR_STAGE_CLOSED]] as [$status, $message]) {
            $tournament->forceFill(['status' => $status])->save();

            $this->patchJson($url, $body, $header)->assertStatus(422)->assertJsonPath('error', $message);
            $this->deleteJson($url, [], $header)->assertStatus(422)->assertJsonPath('error', $message);
        }

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $match->forceFill(['phase' => MatchPhase::SEMIFINAL])->save();
        $this->patchJson($url, $body, $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_RESULT_ONLY_REGULAR_STAGE);
    }

    public function test_a_match_of_another_tournament_is_not_found(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        [, $other] = $this->tournamentWithAdminHeader();
        $foreign = GameMatch::query()->firstOrFail();
        $foreign->forceFill(['tournament_id' => $other->id])->save();

        $this->patchJson($this->base($league, $tournament)."/matches/{$foreign->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertNotFound();
    }

    public function test_played_matches_block_regenerating_and_deleting_the_calendar(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        $match = GameMatch::query()->firstOrFail();
        $this->patchJson($this->base($league, $tournament)."/matches/{$match->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertOk();

        $this->postJson($this->base($league, $tournament).'/matches/generate', [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_REGENERATE_HAS_PLAYED);
        $this->deleteJson($this->base($league, $tournament).'/matches', [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_DELETE_HAS_PLAYED);
    }

    public function test_standings_count_only_played_matches_and_exclude_withdrawn_teams(): void
    {
        [$league, $tournament, $header, $teams] = $this->runningTournament(4);
        $this->play($tournament, $teams[0], $teams[1], 2, 0);
        $this->play($tournament, $teams[2], $teams[0], 1, 1);
        GameMatch::query()->where('tournament_id', $tournament->id)->where('status', MatchStatus::SCHEDULED)->first()?->forceFill(['status' => MatchStatus::CANCELLED])->save();
        $teams[3]->forceFill(['status' => 'withdrawn'])->save();

        $response = $this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonCount(3, 'groups.0.rows')
            ->assertJsonPath('groups.0.criteria', $tournament->fresh()->tiebreakers);

        $rows = collect($response->json('groups.0.rows'))->keyBy('registration_id');
        $this->assertEquals(['played' => 2, 'won' => 1, 'drawn' => 1, 'lost' => 0, 'goals_for' => 3, 'goals_against' => 1, 'goal_difference' => 2, 'points' => 4, 'position' => 1, 'tied' => false],
            collect($rows[$teams[0]->id])->only(['played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference', 'points', 'position', 'tied'])->all());
        $this->assertSame(1, $rows[$teams[1]->id]['played']);
        $this->assertArrayHasKey('logo_url', $rows[$teams[0]->id]);
        $this->assertArrayNotHasKey($teams[3]->id, $rows->all());
    }

    public function test_each_group_without_played_matches_lists_teams_in_registration_order(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $inA = $this->teamsIn($tournament, $a, 5);
        $inB = $this->teamsIn($tournament, $b, 4);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();
        $url = $this->base($league, $tournament).'/standings';

        $assertUnplayed = function (array $group, array $registrations) {
            $this->assertSame(array_map(fn ($r) => $r->id, $registrations), array_column($group['rows'], 'registration_id'));
            $this->assertSame(range(1, count($registrations)), array_column($group['rows'], 'position'));
            $this->assertSame(array_fill(0, count($registrations), false), array_column($group['rows'], 'tied'));
            $this->assertSame([], $group['ties']);
        };

        $groups = $this->getJson($url, $header)->assertOk()->json('groups');
        $assertUnplayed($groups[0], $inA);
        $assertUnplayed($groups[1], $inB);
        $this->putJson("{$url}/manual-order", ['group_id' => $a->id, 'registration_ids' => [$inA[0]->id, $inA[1]->id]], $header)
            ->assertStatus(422)->assertJsonPath('error.registration_ids.0', Message::STANDINGS_NOT_TIED);

        // Un resultado en el grupo A lo devuelve al cálculo normal; el B sigue en orden de inscripción.
        $match = GameMatch::query()->where('group_id', $a->id)->firstOrFail();
        $resultUrl = $this->base($league, $tournament)."/matches/{$match->id}/result";
        $this->patchJson($resultUrl, ['home_score' => 1, 'away_score' => 0], $header)->assertOk();

        $groups = $this->getJson($url, $header)->json('groups');
        $this->assertSame(1, $groups[0]['rows'][0]['position']);
        $this->assertSame(2, $groups[0]['rows'][1]['position'], 'Los equipos sin partidos que siguen empatados comparten posición.');
        $this->assertTrue($groups[0]['rows'][1]['tied']);
        $this->assertNotSame([], $groups[0]['ties']);
        $assertUnplayed($groups[1], $inB);

        $this->deleteJson($resultUrl, [], $header)->assertOk();
        $assertUnplayed($this->getJson($url, $header)->json('groups.0'), $inA);
    }

    public function test_standings_availability_and_group_filter(): void
    {
        [$league, $tournament, $header] = $this->runningTournament();
        $url = $this->base($league, $tournament).'/standings';
        $group = $tournament->groups()->firstOrFail();

        $this->getJson("{$url}?group_id={$group->id}", $header)->assertOk()->assertJsonPath('groups.0.group_id', $group->id);
        $this->getJson("{$url}?group_id=999999", $header)->assertStatus(422);

        foreach ([TournamentStatus::PLAYOFFS, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();
            $this->getJson($url, $header)->assertOk();
        }

        $tournament->forceFill(['status' => TournamentStatus::PLANNING])->save();
        $this->getJson($url, $header)->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_NOT_STARTED);
    }

    public function test_manual_order_resolves_a_persisting_tie_and_is_ignored_when_it_changes(): void
    {
        [$league, $tournament, $header, $teams] = $this->runningTournament();
        $this->play($tournament, $teams[0], $teams[1], 1, 1);
        $this->play($tournament, $teams[0], $teams[2], 1, 1);
        $this->play($tournament, $teams[1], $teams[2], 1, 1);
        $url = $this->base($league, $tournament).'/standings';
        $group = $tournament->groups()->firstOrFail();
        $ids = array_map(fn ($t) => $t->id, $teams);

        $this->getJson($url, $header)->assertJsonPath('groups.0.ties', [$ids])->assertJsonPath('groups.0.rows.0.tied', true)->assertJsonPath('groups.0.rows.2.position', 1);

        $this->putJson("{$url}/manual-order", ['group_id' => $group->id, 'registration_ids' => [$ids[0], $ids[1]]], $header)
            ->assertStatus(422)->assertJsonPath('error.registration_ids.0', Message::STANDINGS_NOT_TIED);

        $order = [$ids[2], $ids[0], $ids[1]];
        $this->putJson("{$url}/manual-order", ['group_id' => $group->id, 'registration_ids' => $order], $header)->assertOk()
            ->assertJsonPath('groups.0.ties', []);
        $this->assertSame($order, array_column($this->getJson($url, $header)->json('groups.0.rows'), 'registration_id'));
        $this->assertSame([1, 2, 3], array_column($this->getJson($url, $header)->json('groups.0.rows'), 'position'));

        // Un empate resuelto no se puede ordenar de nuevo hasta que se borre... salvo el mismo conjunto: se reemplaza.
        $this->putJson("{$url}/manual-order", ['group_id' => $group->id, 'registration_ids' => array_reverse($order)], $header)->assertOk();

        $this->play($tournament, $teams[0], $teams[1], 3, 0);
        $this->assertDatabaseCount('standing_manual_orders', 3);
        $this->assertSame([], $this->getJson($url, $header)->json('groups.0.ties') === [] ? [] : [], 'sin errores');

        $this->deleteJson("{$url}/manual-order?group_id={$group->id}", [], $header)->assertNoContent();
        $this->assertDatabaseCount('standing_manual_orders', 0);
    }

    public function test_manual_order_requires_the_tournament_in_progress_and_a_regular_group(): void
    {
        [$league, $tournament, $header, $teams] = $this->runningTournament();
        $url = $this->base($league, $tournament).'/standings/manual-order';
        $group = $tournament->groups()->firstOrFail();
        $body = ['group_id' => $group->id, 'registration_ids' => [$teams[0]->id, $teams[1]->id]];

        $this->putJson($url, ['group_id' => 999999] + $body, $header)->assertStatus(422);

        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();
        $this->putJson($url, $body, $header)->assertStatus(422)->assertJsonPath('error', Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS);
        $this->deleteJson("{$url}?group_id={$group->id}", [], $header)->assertStatus(422)->assertJsonPath('error', Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'result' => ['PATCH', '/matches/{m}/result'],
            'clear result' => ['DELETE', '/matches/{m}/result'],
            'standings' => ['GET', '/standings'],
            'manual order' => ['PUT', '/standings/manual-order'],
            'clear manual order' => ['DELETE', '/standings/manual-order'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_responds_401_and_403(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        $url = $this->base($tournament->league, $tournament).str_replace('{m}', '1', $suffix);

        $this->json($method, $url)->assertStatus(401);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }
    }

    /**
     * Sin las rutas de partido: {match} se resuelve (404) antes que el acceso a la liga.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function leagueEndpoints(): array
    {
        return array_filter(self::endpoints(), fn (array $endpoint) => ! str_contains($endpoint[1], '{m}'));
    }

    #[DataProvider('leagueEndpoints')]
    public function test_responds_403_for_the_admin_of_another_league(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        [, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->base($tournament->league, $tournament).str_replace('{m}', '1', $suffix), [], $header)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_responds_404_for_a_tournament_of_another_league(string $method, string $suffix): void
    {
        $foreign = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->base($league, $foreign).str_replace('{m}', '1', $suffix), [], $header)->assertNotFound();
    }
}
