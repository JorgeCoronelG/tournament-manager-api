<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LeagueLimitsTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $limits
     * @return array{0: League, 1: array<string, string>}
     */
    private function league(array $limits = []): array
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id] + $limits);

        return [$league, $this->authHeader($admin->createToken('t')->plainTextToken)];
    }

    /**
     * @return array<string, string>
     */
    private function superadmin(): array
    {
        [, $token] = $this->actingAsSuperadmin();

        return $this->authHeader($token);
    }

    private function tournament(League $league, TournamentStatus $status = TournamentStatus::PLANNING, int $registrations = 2): Tournament
    {
        $tournament = Tournament::factory()->status($status)->create(['league_id' => $league->id]);

        for ($i = 0; $i < $registrations; $i++) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);
        }

        return $tournament;
    }

    private function start(League $league, Tournament $tournament, array $header): TestResponse
    {
        return $this->patchJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/status", ['status' => 'in_progress'], $header);
    }

    private function register(League $league, Tournament $tournament, int $count, array $header): TestResponse
    {
        $ids = Team::factory()->count($count)->create(['league_id' => $league->id])->pluck('id')->all();

        return $this->postJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/registrations", ['team_ids' => $ids], $header);
    }

    // --- Alta y edición -------------------------------------------------------------------------------------------

    public function test_save_requires_the_active_tournaments_limit_but_accepts_null(): void
    {
        $header = $this->superadmin();
        $admin = $this->createLeagueAdmin();
        $base = ['name' => 'Liga Límites', 'admin_user_id' => $admin->id];

        $this->postJson('/api/leagues', $base, $header)->assertStatus(422)->assertJsonStructure(['error' => ['max_active_tournaments']]);

        // Sin is_pilot ni límite de equipos: se crea y el límite de equipos queda en null.
        $this->postJson('/api/leagues', $base + ['max_active_tournaments' => null], $header)->assertCreated()
            ->assertJsonPath('max_active_tournaments', null)->assertJsonPath('max_teams_per_tournament', null)->assertJsonMissingPath('is_pilot');

        $league = League::factory()->create(['admin_user_id' => $admin->id]);
        $this->putJson("/api/leagues/{$league->id}", ['name' => 'Otra', 'admin_user_id' => $admin->id], $header)->assertStatus(422)
            ->assertJsonStructure(['error' => ['max_active_tournaments']]);
        $this->putJson("/api/leagues/{$league->id}", ['name' => 'Otra', 'admin_user_id' => $admin->id, 'max_active_tournaments' => null], $header)->assertOk();
    }

    public function test_save_validates_ranges_and_persists_the_limit(): void
    {
        $header = $this->superadmin();
        $admin = $this->createLeagueAdmin();
        $base = ['name' => 'Liga Límites', 'admin_user_id' => $admin->id];

        foreach ([['max_active_tournaments' => 0], ['max_active_tournaments' => 'x'], ['max_active_tournaments' => 1, 'max_teams_per_tournament' => 1]] as $limits) {
            $this->postJson('/api/leagues', $base + $limits, $header)->assertStatus(422);
        }

        $id = $this->postJson('/api/leagues', $base + ['max_active_tournaments' => 1], $header)->assertCreated()->assertJsonPath('max_active_tournaments', 1)->json('id');

        // Editar aplica de inmediato.
        $this->putJson("/api/leagues/{$id}", $base + ['max_active_tournaments' => 3], $header)->assertOk()->assertJsonPath('max_active_tournaments', 3);
        $this->assertDatabaseHas('leagues', ['id' => $id, 'max_active_tournaments' => 3, 'max_teams_per_tournament' => null]);
    }

    public function test_the_reserved_team_limit_is_optional_and_not_modified_when_absent_on_update(): void
    {
        $header = $this->superadmin();
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id, 'max_teams_per_tournament' => 8]);
        $body = ['name' => 'Liga Plan', 'admin_user_id' => $admin->id, 'max_active_tournaments' => null];

        $this->putJson("/api/leagues/{$league->id}", $body, $header)->assertOk()->assertJsonPath('max_teams_per_tournament', 8);
        $this->putJson("/api/leagues/{$league->id}", $body + ['max_teams_per_tournament' => null], $header)->assertOk()->assertJsonPath('max_teams_per_tournament', null);
    }

    public function test_a_league_admin_cannot_edit_the_limits(): void
    {
        [$league, $header] = $this->league();

        $this->putJson("/api/leagues/{$league->id}", ['name' => 'X', 'admin_user_id' => $league->admin_user_id, 'max_active_tournaments' => 9], $header)->assertForbidden();
    }

    // --- Torneos activos ------------------------------------------------------------------------------------------

    public function test_starting_a_second_tournament_is_blocked_by_the_active_limit(): void
    {
        [$league, $header] = $this->league(['max_active_tournaments' => 1]);
        $this->tournament($league, TournamentStatus::IN_PROGRESS);
        $next = $this->tournament($league);

        $this->start($league, $next, $header)->assertStatus(422)->assertJsonPath('error', Message::getMessageLeagueLimitActiveTournaments(1));
        $this->assertSame(TournamentStatus::PLANNING, $next->fresh()->status);

        // En liguilla también ocupa un lugar.
        Tournament::query()->where('league_id', $league->id)->where('status', TournamentStatus::IN_PROGRESS)->update(['status' => TournamentStatus::PLAYOFFS]);
        $this->start($league, $next, $header)->assertStatus(422);
    }

    public function test_planning_and_finished_tournaments_do_not_count_and_the_superadmin_is_limited_too(): void
    {
        [$league] = $this->league(['max_active_tournaments' => 1]);
        $this->tournament($league, TournamentStatus::FINISHED);
        $this->tournament($league);
        $next = $this->tournament($league);
        $superadmin = $this->superadmin();

        $this->start($league, $next, $superadmin)->assertOk()->assertJsonPath('status', 'in_progress');

        $another = $this->tournament($league);
        $this->start($league, $another, $superadmin)->assertStatus(422)->assertJsonPath('error', Message::getMessageLeagueLimitActiveTournaments(1));
    }

    public function test_creating_planning_tournaments_is_not_limited(): void
    {
        [$league, $header] = $this->league(['max_active_tournaments' => 1]);
        $this->tournament($league, TournamentStatus::IN_PROGRESS);

        $this->postJson("/api/leagues/{$league->id}/tournaments", ['name' => 'Copa', 'gender' => 'male', 'min_age' => 18, 'max_age' => 40, 'start_date' => '2026-12-01'], $header)->assertCreated();
    }

    public function test_null_limits_do_not_restrict(): void
    {
        [$league, $header] = $this->league();
        $this->tournament($league, TournamentStatus::IN_PROGRESS);
        $this->tournament($league, TournamentStatus::IN_PROGRESS);

        $this->start($league, $this->tournament($league, TournamentStatus::PLANNING, 30), $header)->assertOk();
        $this->register($league, $this->tournament($league, TournamentStatus::PLANNING, 0), 40, $header)->assertCreated();
    }

    // --- Equipos por torneo ---------------------------------------------------------------------------------------

    public function test_registration_over_the_team_limit_is_blocked_and_a_withdrawal_frees_the_place(): void
    {
        [$league, $header] = $this->league(['max_teams_per_tournament' => 8]);
        $tournament = $this->tournament($league, TournamentStatus::PLANNING, 6);

        $this->register($league, $tournament, 3, $header)->assertStatus(422)->assertJsonPath('error', Message::getMessageLeagueLimitTeams(8));
        $this->assertSame(6, $tournament->registrations()->count());

        $this->register($league, $tournament, 2, $header)->assertCreated();
        $this->register($league, $tournament, 1, $header)->assertStatus(422);

        // Una baja libera su lugar; un equipo de la liga sin inscripción no cuenta.
        Registration::query()->where('tournament_id', $tournament->id)->firstOrFail()->forceFill(['status' => 'withdrawn'])->save();
        Team::factory()->count(5)->create(['league_id' => $league->id]);
        $this->register($league, $tournament, 1, $header)->assertCreated();
    }

    public function test_banned_teams_do_not_count_toward_the_team_limit(): void
    {
        [$league, $header] = $this->league(['max_teams_per_tournament' => 3]);
        $tournament = $this->tournament($league, TournamentStatus::PLANNING, 2);
        $banned = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $banned->id]);
        $banned->forceFill(['status' => TeamStatus::BANNED])->save();

        $this->register($league, $tournament, 1, $header)->assertCreated();
        $this->register($league, $tournament, 1, $header)->assertStatus(422);
    }

    public function test_lowering_the_limit_keeps_what_exists_but_blocks_new_starts_and_registrations(): void
    {
        [$league, $header] = $this->league();
        $running = $this->tournament($league, TournamentStatus::IN_PROGRESS, 6);
        $planning = $this->tournament($league, TournamentStatus::PLANNING, 6);

        $league->forceFill(['max_active_tournaments' => 1, 'max_teams_per_tournament' => 4])->save();

        $this->assertSame(TournamentStatus::IN_PROGRESS, $running->fresh()->status);
        $this->assertSame(6, $running->registrations()->count());
        $this->register($league, $planning, 1, $header)->assertStatus(422);
        $this->start($league, $planning, $header)->assertStatus(422);

        // Con el uso activo al límite pero equipos de más: el mensaje es el de equipos tras liberar el cupo de torneos.
        $running->forceFill(['status' => TournamentStatus::FINISHED])->save();
        $this->start($league, $planning, $header)->assertStatus(422)->assertJsonPath('error', Message::getMessageLeagueLimitTeams(4));

        $league->forceFill(['max_teams_per_tournament' => 6])->save();
        $this->start($league, $planning, $header)->assertOk();
    }

    // --- Uso -----------------------------------------------------------------------------------------------------

    public function test_limits_endpoint_reports_limits_and_usage(): void
    {
        [$league, $header] = $this->league(['max_active_tournaments' => 1, 'max_teams_per_tournament' => 16]);
        $running = $this->tournament($league, TournamentStatus::IN_PROGRESS, 3);
        $this->tournament($league, TournamentStatus::FINISHED, 2);
        $planning = $this->tournament($league, TournamentStatus::PLANNING, 1);
        Registration::factory()->withdrawn()->create(['tournament_id' => $planning->id]);
        $this->tournament(League::factory()->create(), TournamentStatus::IN_PROGRESS, 4);

        $this->getJson("/api/leagues/{$league->id}/limits", $header)->assertOk()->assertExactJson([
            'max_active_tournaments' => 1,
            'max_teams_per_tournament' => 16,
            'usage' => [
                'active_tournaments' => 1,
                'tournaments' => [
                    ['id' => $running->id, 'name' => $running->name, 'status' => 'in_progress', 'active_registrations' => 3],
                    ['id' => $planning->id, 'name' => $planning->name, 'status' => 'planning', 'active_registrations' => 1],
                ],
            ],
        ]);
    }

    public function test_limits_endpoint_access(): void
    {
        [$league, $header] = $this->league();
        [$other] = $this->league();
        $other->forceFill(['max_active_tournaments' => null])->save();

        $this->getJson("/api/leagues/{$other->id}/limits", $header)->assertForbidden();
        $this->getJson("/api/leagues/{$league->id}/limits", $this->superadmin())->assertOk();

        // El guard conserva al usuario de la petición anterior dentro de la misma prueba.
        $this->app['auth']->forgetGuards();
        $referee = User::factory()->create();
        $referee->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $this->getJson("/api/leagues/{$league->id}/limits", $this->authHeader($referee->createToken('t')->plainTextToken))->assertForbidden();
    }
}
