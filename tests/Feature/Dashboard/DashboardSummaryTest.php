<?php

namespace Tests\Feature\Dashboard;

use App\Core\Enum\LeagueStatus;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Users\InteractsWithSuperadmin;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    private const URL = '/api/dashboard/summary';

    protected function setUp(): void
    {
        parent::setUp();

        // Miércoles: la semana va del lunes 16 al domingo 22 de noviembre.
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'America/Mexico_City'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        [, $token] = $this->actingAsSuperadmin();

        return $this->getJson(self::URL, $this->authHeader($token))->assertOk()->json();
    }

    private function tournament(League $league, TournamentStatus $status = TournamentStatus::IN_PROGRESS): Tournament
    {
        return Tournament::factory()->status($status)->create(['league_id' => $league->id]);
    }

    private function register(Tournament $tournament, ?Team $team = null): Registration
    {
        return Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => ($team ?? Team::factory()->create(['league_id' => $tournament->league_id]))->id]);
    }

    private function match(Tournament $tournament, ?string $at, MatchStatus $status = MatchStatus::SCHEDULED): GameMatch
    {
        return GameMatch::query()->forceCreate([
            'tournament_id' => $tournament->id,
            'phase' => MatchPhase::REGULAR_STAGE,
            'status' => $status,
            'match_datetime' => $at,
        ]);
    }

    public function test_only_the_superadmin_can_read_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        foreach ([RoleEnum::LEAGUE_ADMIN, RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($this->createRole($role));

            $this->getJson(self::URL, $this->authHeader($user->createToken('t')->plainTextToken))->assertForbidden();
        }
    }

    public function test_empty_system_and_shape(): void
    {
        $this->assertSame([
            'league_id' => null,
            'leagues_active' => 0,
            'tournaments_active' => 0,
            'teams_in_active_tournaments' => 0,
            'players_in_active_tournaments' => 0,
            'matches_this_week' => 0,
            'referees_active' => 0,
            'week' => ['from' => '2026-11-16', 'to' => '2026-11-22'],
        ], $this->summary());
    }

    public function test_only_active_leagues_and_active_tournaments_count(): void
    {
        $active = League::factory()->create();
        $inactive = League::factory()->create(['status' => LeagueStatus::INACTIVE]);
        $deleted = League::factory()->create();

        $this->tournament($active);
        $this->tournament($active, TournamentStatus::PLAYOFFS);
        $this->tournament($active, TournamentStatus::PLANNING);
        $this->tournament($active, TournamentStatus::FINISHED);
        $this->tournament($inactive);
        $this->tournament($deleted);
        $deleted->delete();

        $data = $this->summary();

        $this->assertSame(1, $data['leagues_active'], 'La eliminada y la inactiva no cuentan.');
        $this->assertSame(2, $data['tournaments_active']);
    }

    public function test_a_team_counts_once_and_banned_or_withdrawn_do_not(): void
    {
        $league = League::factory()->create();
        $one = $this->tournament($league);
        $two = $this->tournament($league, TournamentStatus::PLAYOFFS);
        $shared = Team::factory()->create(['league_id' => $league->id]);
        $this->register($one, $shared);
        $this->register($two, $shared);
        $this->register($one);
        $this->register($one, Team::factory()->create(['league_id' => $league->id, 'status' => TeamStatus::BANNED]));
        Registration::factory()->withdrawn()->create(['tournament_id' => $one->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);
        $this->register($this->tournament($league, TournamentStatus::FINISHED));

        $this->assertSame(2, $this->summary()['teams_in_active_tournaments']);
    }

    public function test_a_player_counts_once_and_only_with_an_active_roster(): void
    {
        $league = League::factory()->create();
        $one = $this->tournament($league);
        $two = $this->tournament($league);
        $first = Roster::factory()->forRegistration($this->register($one))->create();
        Roster::factory()->forRegistration($this->register($two))->create(['player_id' => $first->player_id]);
        Roster::factory()->forRegistration($this->register($one))->create();

        foreach ([RosterStatus::PENDING, RosterStatus::RELEASED, RosterStatus::REJECTED, RosterStatus::CANCELLED, RosterStatus::REMOVED] as $status) {
            Roster::factory()->forRegistration($this->register($one))->status($status)->create();
        }

        $this->assertSame(2, $this->summary()['players_in_active_tournaments']);
    }

    public function test_matches_of_the_week_use_its_boundaries_and_status(): void
    {
        $league = League::factory()->create();
        $tournament = $this->tournament($league);

        $this->match($tournament, '2026-11-16 00:00:00');
        $this->match($tournament, '2026-11-18 20:00:00', MatchStatus::PLAYED);
        $this->match($tournament, '2026-11-22 23:30:00');
        $this->match($tournament, '2026-11-23 00:10:00');
        $this->match($tournament, '2026-11-15 23:59:00');
        $this->match($tournament, '2026-11-19 10:00:00', MatchStatus::CANCELLED);
        $this->match($tournament, null);
        $this->match($tournament, '2026-11-20 10:00:00')->delete();
        $this->match($this->tournament(League::factory()->create(['status' => LeagueStatus::INACTIVE])), '2026-11-20 10:00:00');

        $this->assertSame(3, $this->summary()['matches_this_week']);
    }

    public function test_referees_count_once_across_active_leagues(): void
    {
        $a = League::factory()->create();
        $b = League::factory()->create();
        $inactiveLeague = League::factory()->create(['status' => LeagueStatus::INACTIVE]);
        $shared = User::factory()->create();

        LeagueReferee::factory()->create(['league_id' => $a->id, 'user_id' => $shared->id]);
        LeagueReferee::factory()->create(['league_id' => $b->id, 'user_id' => $shared->id]);
        LeagueReferee::factory()->create(['league_id' => $a->id]);
        LeagueReferee::factory()->inactive()->create(['league_id' => $a->id]);
        LeagueReferee::factory()->create(['league_id' => $inactiveLeague->id]);

        $this->assertSame(2, $this->summary()['referees_active']);
    }

    public function test_deactivating_a_league_removes_its_figures_and_reactivating_restores_them(): void
    {
        $league = League::factory()->create();
        $tournament = $this->tournament($league);
        Roster::factory()->forRegistration($this->register($tournament))->create();
        $this->match($tournament, '2026-11-18 20:00:00');
        LeagueReferee::factory()->create(['league_id' => $league->id]);

        // Las fábricas crean otras ligas activas de relleno: se compara contra lo que queda sin esta liga.
        $with = $this->figures($this->summary());

        $league->forceFill(['status' => LeagueStatus::INACTIVE])->save();
        $without = $this->figures($this->summary());
        $this->assertSame([1, 1, 1, 1, 1, 1], array_map(fn (int $a, int $b): int => $a - $b, $with, $without));

        $league->forceFill(['status' => LeagueStatus::ACTIVE])->save();
        $this->assertSame($with, $this->figures($this->summary()));

        $league->delete();
        $this->assertSame($without, $this->figures($this->summary()));
    }

    /**
     * Liga con un torneo en curso, un equipo, un jugador, un partido esta semana y un árbitro: todas las cifras en 1.
     */
    private function leagueWithOneOfEach(LeagueStatus $status = LeagueStatus::ACTIVE): League
    {
        $league = League::factory()->create(['status' => $status]);
        $tournament = $this->tournament($league);
        Roster::factory()->forRegistration($this->register($tournament))->create();
        $this->match($tournament, '2026-11-18 20:00:00');
        LeagueReferee::factory()->create(['league_id' => $league->id]);

        return $league;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFor(string $query): array
    {
        [, $token] = $this->actingAsSuperadmin();

        return $this->getJson(self::URL.$query, $this->authHeader($token))->assertOk()->json();
    }

    public function test_filtering_by_league_counts_only_that_league_but_leagues_active_stays_global(): void
    {
        $a = $this->leagueWithOneOfEach();
        $b = $this->leagueWithOneOfEach();
        $this->withoutFillerLeagues([$a->id, $b->id]);
        $this->tournament($b);
        $this->tournament($b);

        $global = $this->summary();
        $forA = $this->summaryFor("?league_id={$a->id}");
        $forB = $this->summaryFor("?league_id={$b->id}");

        $this->assertSame([$a->id, 2], [$forA['league_id'], $global['leagues_active']]);
        $this->assertSame(2, $forA['leagues_active']);
        $this->assertSame([1, 1, 1, 1, 1], $this->five($forA));
        $this->assertSame([3, 1, 1, 1, 1], $this->five($forB));
        $this->assertSame($global['week'], $forA['week']);

        // Con solo dos ligas activas, la suma de las filtradas es la global.
        foreach (range(0, 4) as $i) {
            $this->assertSame($this->five($global)[$i], $this->five($forA)[$i] + $this->five($forB)[$i]);
        }
    }

    public function test_an_inactive_league_returns_its_real_figures_only_when_asked_explicitly(): void
    {
        $inactive = $this->leagueWithOneOfEach(LeagueStatus::INACTIVE);
        $this->withoutFillerLeagues([$inactive->id]);

        $this->assertSame([1, 1, 1, 1, 1], $this->five($this->summaryFor("?league_id={$inactive->id}")));
        $this->assertSame(0, $this->summaryFor("?league_id={$inactive->id}")['leagues_active']);
        $this->assertSame([0, 0, 0, 0, 0], $this->five($this->summary()));
    }

    public function test_invalid_unknown_or_deleted_league_id_is_an_error(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $header = $this->authHeader($token);
        $deleted = League::factory()->create();
        $deleted->delete();

        $this->getJson(self::URL.'?league_id=abc', $header)->assertStatus(400);
        $this->getJson(self::URL.'?league_id=0', $header)->assertStatus(400);
        $this->getJson(self::URL.'?league_id=999999', $header)->assertNotFound();
        $this->getJson(self::URL."?league_id={$deleted->id}", $header)->assertNotFound();
    }

    public function test_a_league_admin_cannot_use_the_global_summary(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        $this->getJson(self::URL, $this->authHeader($user->createToken('t')->plainTextToken))->assertForbidden();
    }

    public function test_league_admin_summary_has_its_own_figures_and_no_global_fields(): void
    {
        $mine = $this->leagueWithOneOfEach();
        $this->leagueWithOneOfEach();
        $header = $this->adminHeader($mine);

        $response = $this->getJson("/api/leagues/{$mine->id}/dashboard/summary", $header)->assertOk();

        $this->assertSame(
            ['tournaments_active', 'teams_in_active_tournaments', 'players_in_active_tournaments', 'matches_this_week', 'referees_active', 'week'],
            array_keys($response->json())
        );
        $this->assertSame([1, 1, 1, 1, 1], $this->five($response->json()));
        $this->assertSame(['from' => '2026-11-16', 'to' => '2026-11-22'], $response->json('week'));
    }

    public function test_league_admin_summary_is_forbidden_for_other_leagues_inactive_league_and_other_roles(): void
    {
        $mine = $this->leagueWithOneOfEach();
        $other = $this->leagueWithOneOfEach();
        $header = $this->adminHeader($mine);

        $this->getJson("/api/leagues/{$other->id}/dashboard/summary", $header)->assertForbidden();

        $mine->forceFill(['status' => LeagueStatus::INACTIVE])->save();
        $this->getJson("/api/leagues/{$mine->id}/dashboard/summary", $header)->assertForbidden()->assertJsonPath('error', Message::LEAGUE_INACTIVE);

        $referee = User::factory()->create();
        $referee->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $this->getJson("/api/leagues/{$other->id}/dashboard/summary", $this->authHeader($referee->createToken('t')->plainTextToken))->assertForbidden();
    }

    public function test_superadmin_can_read_the_league_summary_even_if_inactive(): void
    {
        $inactive = $this->leagueWithOneOfEach(LeagueStatus::INACTIVE);
        [, $token] = $this->actingAsSuperadmin();

        $response = $this->getJson("/api/leagues/{$inactive->id}/dashboard/summary", $this->authHeader($token))->assertOk();

        $this->assertSame([1, 1, 1, 1, 1], $this->five($response->json()));
    }

    /**
     * Las fábricas crean ligas de relleno al armar inscripciones y plantillas: se eliminan para comparar con el total.
     *
     * @param  list<int>  $keep
     */
    private function withoutFillerLeagues(array $keep): void
    {
        League::query()->whereNotIn('id', $keep)->get()->each->delete();
    }

    /**
     * @return array<string, string>
     */
    private function adminHeader(League $league): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));
        $league->forceFill(['admin_user_id' => $admin->id])->save();

        return $this->authHeader($admin->createToken('t')->plainTextToken);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function five(array $data): array
    {
        return [
            $data['tournaments_active'], $data['teams_in_active_tournaments'], $data['players_in_active_tournaments'],
            $data['matches_this_week'], $data['referees_active'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function figures(array $data): array
    {
        return [
            $data['leagues_active'], $data['tournaments_active'], $data['teams_in_active_tournaments'],
            $data['players_in_active_tournaments'], $data['matches_this_week'], $data['referees_active'],
        ];
    }
}
