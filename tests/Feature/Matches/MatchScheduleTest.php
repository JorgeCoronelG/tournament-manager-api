<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class MatchScheduleTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function url(League $league, Tournament $tournament, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/matches{$suffix}";
    }

    /**
     * Torneo en curso con un grupo de 5 equipos y otro de 4, a doble vuelta.
     *
     * @return array{0: League, 1: Tournament, 2: array<string, string>}
     */
    private function oddTournament(int $rounds = 2): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $this->teamsIn($tournament, $a, 5);
        $this->teamsIn($tournament, $b, 4);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => $rounds])->save();

        return [$league, $tournament, $header];
    }

    public function test_generates_the_double_round_calendar_for_odd_and_even_groups(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();

        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)
            ->assertOk()
            ->assertJsonPath('total_matches', 32)
            ->assertJsonPath('groups.0', ['group_id' => $tournament->groups()->orderBy('id')->value('id'), 'group_name' => 'Fase regular', 'teams' => 5, 'matchdays' => 10, 'matches' => 20])
            ->assertJsonPath('groups.1.matchdays', 6)
            ->assertJsonPath('groups.1.matches', 12);

        $this->assertSame(32, GameMatch::query()->count());
        $this->assertSame(0, GameMatch::query()->whereNotNull('match_datetime')->count());
        $this->assertSame(16, GameMatch::query()->where('round_number', 2)->count());
        $this->assertSame(1, (int) GameMatch::query()->where('round_number', 2)->where('group_id', $tournament->groups()->orderBy('id')->value('id'))->min('matchday') - 5);
    }

    public function test_single_round_without_shuffle_follows_registration_order(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registrations = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 4);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        $this->postJson($this->url($league, $tournament, '/generate'), ['shuffle' => false], $header)
            ->assertOk()->assertJsonPath('total_matches', 6)->assertJsonPath('groups.0.matchdays', 3);

        $ids = array_map(fn ($r) => $r->id, $registrations);
        $first = GameMatch::query()->where('matchday', 1)->get()->map(fn ($m) => [$m->home_registration_id, $m->away_registration_id])->flatten()->all();
        $this->assertEqualsCanonicalizing($ids, $first);
        $this->assertSame(0, GameMatch::query()->where('round_number', 2)->count());
    }

    public function test_regenerating_replaces_the_calendar_unless_something_was_played(): void
    {
        [$league, $tournament, $header] = $this->oddTournament(1);
        $url = $this->url($league, $tournament, '/generate');

        $this->postJson($url, [], $header)->assertOk();
        $this->postJson($url, [], $header)->assertOk();
        $this->assertSame(16, GameMatch::query()->count());
        $this->assertSame(16, GameMatch::withTrashed()->count() - 16);

        GameMatch::query()->first()?->forceFill(['status' => MatchStatus::PLAYED])->save();
        $this->postJson($url, [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_REGENERATE_HAS_PLAYED);
        $this->deleteJson($this->url($league, $tournament), [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_DELETE_HAS_PLAYED);
    }

    public function test_generating_requires_the_tournament_in_progress(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();

        foreach ([TournamentStatus::PLANNING, TournamentStatus::PLAYOFFS, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();

            $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_GENERATE_ONLY_IN_PROGRESS);
            $this->deleteJson($this->url($league, $tournament), [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCHES_DELETE_ONLY_IN_PROGRESS);
        }
    }

    public function test_minimum_matches_larger_than_what_a_group_plays_is_rejected(): void
    {
        [$league, $tournament, $header] = $this->oddTournament(1);
        $tournament->forceFill(['min_matches_for_playoffs' => 5])->save();

        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::getMessageMinMatchesExceed(5, 'Fase regular', 4));

        $tournament->forceFill(['min_matches_for_playoffs' => 4])->save();
        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::getMessageMinMatchesExceed(4, 'Grupo B', 3));

        $tournament->forceFill(['min_matches_for_playoffs' => 3])->save();
        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertOk();
    }

    public function test_starting_validates_minimum_matches(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 4);
        $tournament->forceFill(['min_matches_for_playoffs' => 4])->save();
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::getMessageMinMatchesExceed(4, 'Fase regular', 3).' Ajusta el torneo antes de iniciar.');

        $tournament->forceFill(['min_matches_for_playoffs' => 3])->save();
        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_banned_teams_do_not_play(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registrations = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 5);
        $registrations[0]->team->forceFill(['status' => TeamStatus::BANNED])->save();
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertOk()->assertJsonPath('groups.0.teams', 4);
    }

    public function test_deleting_the_calendar_removes_every_regular_match(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();
        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertOk();

        $this->deleteJson($this->url($league, $tournament), [], $header)->assertNoContent();

        $this->assertSame(0, GameMatch::query()->count());
    }

    public function test_lists_matches_with_filters_and_team_data(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();
        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertOk();
        $groupB = $tournament->groups()->where('name', 'Grupo B')->firstOrFail();

        $this->getJson($this->url($league, $tournament), $header)->assertOk()
            ->assertJsonPath('meta.total', 32)
            ->assertJsonStructure(['data' => [['id', 'group_id', 'group_name', 'matchday', 'round_number', 'phase', 'status', 'match_datetime',
                'home' => ['registration_id', 'team_id', 'team_name', 'logo_url'], 'away' => ['registration_id', 'team_id', 'team_name', 'logo_url'],
                'home_score', 'away_score']]]);
        $this->getJson($this->url($league, $tournament).'?group_id='.$groupB->id.'&matchday=1', $header)->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($league, $tournament).'?round_number=2&status=scheduled&phase=regular_stage', $header)->assertJsonPath('meta.total', 16);
        $this->getJson($this->url($league, $tournament).'?status=played', $header)->assertJsonPath('meta.total', 0);
        $this->getJson($this->url($league, $tournament).'?status=nope', $header)->assertStatus(400);
    }

    public function test_schedule_groups_by_group_and_matchday_with_resting_teams(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();
        $this->postJson($this->url($league, $tournament, '/generate'), [], $header)->assertOk();

        $response = $this->getJson($this->url($league, $tournament, '/schedule'), $header)->assertOk()
            ->assertJsonPath('generated', true)
            ->assertJsonCount(2, 'groups')
            ->assertJsonCount(10, 'groups.0.matchdays')
            ->assertJsonCount(6, 'groups.1.matchdays')
            ->assertJsonCount(2, 'groups.0.matchdays.0.matches')
            ->assertJsonCount(1, 'groups.0.matchdays.0.resting')
            ->assertJsonCount(0, 'groups.1.matchdays.0.resting');

        $restingByDay = collect($response->json('groups.0.matchdays'))->pluck('resting.0.registration_id');
        $this->assertCount(5, $restingByDay->unique(), 'Cada equipo descansa una vez por vuelta.');
        $this->assertSame(10, count($response->json('groups.0.matchdays')));
    }

    public function test_empty_schedule_returns_groups_without_matchdays(): void
    {
        [$league, $tournament, $header] = $this->oddTournament();

        $this->getJson($this->url($league, $tournament, '/schedule'), $header)->assertOk()
            ->assertJsonPath('generated', false)
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.matchdays', []);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'schedule' => ['GET', '/schedule'],
            'generate' => ['POST', '/generate'],
            'destroy' => ['DELETE', ''],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_permissions(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        $url = $this->url($tournament->league, $tournament, $suffix);

        $this->json($method, $url)->assertStatus(401);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }

        [, $header] = $this->leagueWithAdminHeader();
        $this->json($method, $url, [], $header)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_responds_404_for_a_tournament_of_another_league(string $method, string $suffix): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $foreign = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();

        $this->json($method, $this->url($league, $foreign, $suffix), [], $header)->assertNotFound();
    }
}
