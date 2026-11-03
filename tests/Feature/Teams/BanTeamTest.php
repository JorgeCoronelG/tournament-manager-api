<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Events\TeamWithdrawn;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class BanTeamTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function ban(League $league, Team $team, array $header, ?string $reason = 'Agredió al árbitro'): TestResponse
    {
        return $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/status", ['status' => 'banned'] + ($reason === null ? [] : ['reason' => $reason]), $header);
    }

    /**
     * Inscribe al equipo en un torneo nuevo de la liga con el estado indicado.
     */
    private function enroll(League $league, Team $team, TournamentStatus $status): Registration
    {
        $tournament = Tournament::factory()->status($status)->create(['league_id' => $league->id]);

        return Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $tournament->groups()->firstOrFail()->id]);
    }

    private function roster(Registration $registration, RosterStatus $status, int $jersey): Roster
    {
        return Roster::factory()->forRegistration($registration)->status($status)->create(['player_id' => Player::factory()->create()->id, 'jersey_number' => $jersey]);
    }

    public function test_banning_requires_a_reason_between_5_and_255_characters(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        foreach ([null, '', 'abc', str_repeat('x', 256)] as $reason) {
            $this->ban($league, $team, $header, $reason)->assertStatus(422)->assertJsonStructure(['error' => ['reason']]);
        }

        $this->assertSame(TeamStatus::ACTIVE, $team->fresh()->status);
        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/status", ['status' => 'active'], $header)->assertOk();
    }

    public function test_banning_a_team_in_a_planning_tournament_removes_the_registration(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $registration = $this->enroll($league, $team, TournamentStatus::PLANNING);
        $row = $this->roster($registration, RosterStatus::ACTIVE, 7);

        $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}/ban/preview", $header)->assertOk()
            ->assertJsonPath('tournaments.0.action', 'removed')
            ->assertJsonPath('tournaments.0.status', 'planning')
            ->assertJsonPath('totals.removed_tournaments', 1);
        $this->assertSame(TeamStatus::ACTIVE, $team->fresh()->status);

        $this->ban($league, $team, $header)->assertOk()
            ->assertJsonPath('status', 'banned')
            ->assertJsonPath('ban_reason', 'Agredió al árbitro')
            ->assertJsonPath('ban_effects.removed_tournaments', 1)
            ->assertJsonPath('ban_effects.withdrawn_tournaments', 0)
            ->assertJsonPath('ban_effects.players_released', 1);

        $this->assertSoftDeleted('registrations', ['id' => $registration->id]);
        $this->assertSame(RosterStatus::RELEASED, $row->fresh()->status);
    }

    public function test_banning_a_team_in_a_running_tournament_withdraws_it_and_keeps_its_pending_matches(): void
    {
        Event::fake([TeamWithdrawn::class]);
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[3];
        $registration->team->forceFill(['league_id' => $league->id])->save();
        $team = $registration->team;
        $matches = GameMatch::query()->where('home_registration_id', $registration->id)->orWhere('away_registration_id', $registration->id)->get();
        $matches->first()->forceFill(['match_datetime' => now()->addDays(5), 'schedule_locked' => true])->save();
        $this->roster($registration, RosterStatus::ACTIVE, 7);
        $this->roster($registration, RosterStatus::PENDING, 8);

        $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}/ban/preview", $header)->assertOk()
            ->assertJsonPath('tournaments.0.tournament_id', $tournament->id)
            ->assertJsonPath('tournaments.0.action', 'withdrawn')
            ->assertJsonPath('tournaments.0.pending_matches', 3)
            ->assertJsonPath('tournaments.0.players_released', 2);

        $this->ban($league, $team, $header, 'Falta grave')->assertOk()
            ->assertJsonPath('ban_effects.withdrawn_tournaments', 1)
            ->assertJsonPath('ban_effects.removed_tournaments', 0)
            ->assertJsonPath('ban_effects.pending_matches', 3)
            ->assertJsonPath('ban_effects.players_released', 2);

        $fresh = $registration->fresh();
        $this->assertSame(RegistrationStatus::WITHDRAWN, $fresh->status);
        $this->assertSame('Equipo vetado: Falta grave', $fresh->withdrawn_reason);
        $this->assertSame($league->admin_user_id, $fresh->withdrawn_by_user_id);
        $this->assertSame(3, $matches->filter(fn (GameMatch $match) => $match->fresh()->status === MatchStatus::SCHEDULED)->count());
        $this->assertNotNull($matches->first()->fresh()->match_datetime);
        $this->assertTrue($matches->first()->fresh()->schedule_locked);
        $this->assertSame(2, Roster::query()->where('registration_id', $registration->id)->where('status', RosterStatus::RELEASED)->count());
        Event::assertDispatched(TeamWithdrawn::class);

        $pending = $matches->first();
        $this->patchJson($this->base($league, $tournament)."/matches/{$pending->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertStatus(422);
    }

    public function test_banning_a_team_in_the_playoffs_withdraws_it(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->inPlayoffs([1, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        $registration = Registration::query()->findOrFail($semifinal->registration_a_id);
        $registration->team->forceFill(['league_id' => $league->id])->save();

        $this->ban($league, $registration->team, $header)->assertOk()
            ->assertJsonPath('ban_effects.withdrawn_tournaments', 1)
            ->assertJsonPath('ban_effects.pending_matches', 1);

        $this->assertSame(RegistrationStatus::WITHDRAWN, $registration->fresh()->status);
        $this->assertNull($semifinal->fresh()->winner_registration_id);
    }

    public function test_banning_a_team_in_several_tournaments_applies_each_rule_and_skips_finished_and_withdrawn(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $planning = $this->enroll($league, $team, TournamentStatus::PLANNING);
        $running = $this->enroll($league, $team, TournamentStatus::IN_PROGRESS);
        $playoffs = $this->enroll($league, $team, TournamentStatus::PLAYOFFS);
        $finished = $this->enroll($league, $team, TournamentStatus::FINISHED);
        $alreadyOut = $this->enroll($league, $team, TournamentStatus::IN_PROGRESS);
        $alreadyOut->forceFill(['status' => RegistrationStatus::WITHDRAWN, 'withdrawn_reason' => 'Baja previa'])->save();

        $preview = $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}/ban/preview", $header)->assertOk();
        $this->assertCount(3, $preview->json('tournaments'));

        $this->ban($league, $team, $header)->assertOk()
            ->assertJsonPath('ban_effects.removed_tournaments', 1)
            ->assertJsonPath('ban_effects.withdrawn_tournaments', 2);

        $this->assertSoftDeleted('registrations', ['id' => $planning->id]);
        $this->assertSame(RegistrationStatus::WITHDRAWN, $running->fresh()->status);
        $this->assertSame(RegistrationStatus::WITHDRAWN, $playoffs->fresh()->status);
        $this->assertSame(RegistrationStatus::ACTIVE, $finished->fresh()->status);
        $this->assertSame('Baja previa', $alreadyOut->fresh()->withdrawn_reason);
    }

    public function test_banning_twice_does_not_repeat_the_effects(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $running = $this->enroll($league, $team, TournamentStatus::IN_PROGRESS);

        $this->ban($league, $team, $header, 'Primer motivo')->assertOk()->assertJsonPath('ban_effects.withdrawn_tournaments', 1);
        $withdrawnAt = $running->fresh()->withdrawn_at;

        $this->ban($league, $team, $header, 'Otro motivo')->assertOk()
            ->assertJsonPath('ban_reason', 'Primer motivo')
            ->assertJsonPath('ban_effects.withdrawn_tournaments', 0);
        $this->assertEquals($withdrawnAt, $running->fresh()->withdrawn_at);
    }

    public function test_reactivating_restores_nothing_and_the_team_cannot_come_back_to_that_tournament(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $running = $this->enroll($league, $team, TournamentStatus::IN_PROGRESS);
        $planning = $this->enroll($league, $team, TournamentStatus::PLANNING);
        $this->ban($league, $team, $header)->assertOk();

        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/status", ['status' => 'active'], $header)->assertOk()
            ->assertJsonPath('ban_reason', null);

        $this->assertSame(RegistrationStatus::WITHDRAWN, $running->fresh()->status);
        $this->assertSoftDeleted('registrations', ['id' => $planning->id]);

        $futureTournament = Tournament::factory()->create(['league_id' => $league->id]);
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$futureTournament->id}/registrations", ['team_ids' => [$team->id]], $header)->assertCreated();
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$planning->tournament_id}/registrations", ['team_ids' => [$team->id]], $header)->assertCreated();
    }

    public function test_a_banned_team_can_be_neither_enrolled_nor_used_as_a_replacement(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $banned = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id]);
        $planning = Tournament::factory()->create(['league_id' => $league->id]);

        $this->postJson("/api/leagues/{$league->id}/tournaments/{$planning->id}/registrations", ['team_ids' => [$banned->id]], $header)->assertStatus(422);
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/registrations/{$teams[0]->id}/replace", ['team_id' => $banned->id, 'reason' => 'Cambio de equipo'], $header)->assertStatus(422);
    }

    public function test_permissions(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $foreign = Team::factory()->create();

        $this->ban(League::query()->findOrFail($foreign->league_id), $foreign, $header)->assertStatus(403);
        $this->ban($league, $foreign, $header)->assertStatus(404);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->app->make('auth')->forgetGuards();
            $this->ban($league, $team, $this->headerForRole($role))->assertStatus(403);
            $this->app->make('auth')->forgetGuards();
            $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}/ban/preview", $this->headerForRole($role))->assertStatus(403);
        }

        $this->assertSame(TeamStatus::ACTIVE, $team->fresh()->status);
    }
}
