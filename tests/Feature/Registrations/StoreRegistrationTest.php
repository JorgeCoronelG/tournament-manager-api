<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreRegistrationTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    public function test_registers_several_teams_as_active(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $teams = Team::factory()->count(2)->create(['league_id' => $league->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => $teams->pluck('id')->all()], $header)
            ->assertCreated()
            ->assertJsonCount(2)
            ->assertJsonPath('0.status', 'active')
            ->assertJsonPath('0.team.id', $teams[0]->id);

        $this->assertSame(2, Registration::query()->where('tournament_id', $tournament->id)->where('status', RegistrationStatus::ACTIVE)->count());
    }

    public function test_superadmin_can_register_in_any_league(): void
    {
        $tournament = Tournament::factory()->create();
        $team = Team::factory()->create(['league_id' => $tournament->league_id]);

        $this->postJson($this->registrationsUrl($tournament->league, $tournament), ['team_ids' => [$team->id]], $this->authHeader($this->actingAsSuperadmin()[1]))
            ->assertCreated();
    }

    public function test_one_failing_team_registers_none_and_the_error_says_which(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $good = Team::factory()->create(['league_id' => $league->id]);
        $banned = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$good->id, $banned->id]], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['team_ids.1']])
            ->assertJsonCount(1, 'error');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_reports_every_failing_team_at_once(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $banned = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id]);
        $foreign = Team::factory()->create(['league_id' => League::factory()->create()->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$banned->id, $foreign->id, 999999]], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_ids.0' => [Message::REGISTRATION_TEAM_BANNED]]])
            ->assertJson(['error' => ['team_ids.1' => [Message::REGISTRATION_TEAM_NOT_IN_LEAGUE]]])
            ->assertJson(['error' => ['team_ids.2' => [Message::REGISTRATION_TEAM_NOT_IN_LEAGUE]]]);
    }

    public function test_rejects_a_deleted_team(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $team->delete();

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_ids.0' => [Message::REGISTRATION_TEAM_NOT_IN_LEAGUE]]]);
    }

    public function test_rejects_a_team_already_registered(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_ids.0' => [Message::REGISTRATION_TEAM_ALREADY_REGISTERED]]]);
    }

    public function test_same_team_in_another_tournament_is_fine(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => Tournament::factory()->create(['league_id' => $league->id])->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)->assertCreated();
    }

    public function test_repeated_name_with_different_managers_is_rejected_ignoring_case_and_spaces(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $this->createManagerUser()->id]);
        $second = Team::factory()->create(['league_id' => $league->id, 'name' => '  TIGRES ', 'manager_user_id' => $this->createManagerUser()->id]);
        Registration::factory()->create(['team_id' => $first->id, 'tournament_id' => $tournament->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$second->id]], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_ids.0' => ['Ya hay un equipo con ese nombre en este torneo.']]]);
    }

    public function test_repeated_names_inside_the_same_request_are_rejected(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $this->createManagerUser()->id]);
        $second = Team::factory()->create(['league_id' => $league->id, 'name' => 'tigres', 'manager_user_id' => $this->createManagerUser()->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$first->id, $second->id]], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['team_ids.1']])
            ->assertJsonCount(1, 'error');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_the_same_name_is_free_again_after_removing_the_registration(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $this->createManagerUser()->id]);
        $second = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $this->createManagerUser()->id]);
        $registration = Registration::factory()->create(['team_id' => $first->id, 'tournament_id' => $tournament->id]);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNoContent();
        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$second->id]], $header)->assertCreated();
    }

    public function test_a_removed_team_can_be_registered_again(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $registration = Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
        $registration->delete();

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
            ->assertCreated()
            ->assertJsonPath('0.id', $registration->id);

        $this->assertDatabaseCount('registrations', 1);
        $this->assertNull($registration->fresh()->deleted_at);
    }

    public function test_rejects_a_tournament_that_is_not_in_planning(): void
    {
        [$league, , $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        foreach ([TournamentStatus::IN_PROGRESS, TournamentStatus::FINISHED] as $status) {
            $tournament = Tournament::factory()->status($status)->create(['league_id' => $league->id]);

            $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
                ->assertStatus(422)
                ->assertJsonPath('error', Message::REGISTRATION_TOURNAMENT_NOT_PLANNING);
        }

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_a_tournament_of_another_league_in_the_route_is_404(): void
    {
        [$league, , $header] = $this->tournamentWithAdminHeader();
        $foreign = Tournament::factory()->create();
        $team = Team::factory()->create(['league_id' => $league->id]);

        $this->postJson($this->registrationsUrl($league, $foreign), ['team_ids' => [$team->id]], $header)->assertNotFound();
    }

    public function test_validates_the_body(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $url = $this->registrationsUrl($league, $tournament);

        $this->postJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['team_ids']]);
        $this->postJson($url, ['team_ids' => []], $header)->assertStatus(422)->assertJsonStructure(['error' => ['team_ids']]);
        $this->postJson($url, ['team_ids' => 'x'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['team_ids']]);
        $this->postJson($url, ['team_ids' => ['abc']], $header)->assertStatus(422)->assertJsonStructure(['error' => ['team_ids.0']]);
        $this->postJson($url, ['team_ids' => [1, 1]], $header)->assertStatus(422)->assertJsonStructure(['error' => ['team_ids.0', 'team_ids.1']]);
    }

    public function test_banning_a_team_with_active_registrations_is_still_allowed(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);

        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/status", ['status' => 'banned'], $header)->assertOk();
    }
}
