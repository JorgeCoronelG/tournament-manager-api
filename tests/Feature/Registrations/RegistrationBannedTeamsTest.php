<?php

namespace Tests\Feature\Registrations;

use App\Contracts\Services\RegistrationServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationBannedTeamsTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    private function enroll(Tournament $tournament, TeamStatus $status = TeamStatus::ACTIVE): Registration
    {
        return Registration::factory()->create([
            'tournament_id' => $tournament->id,
            'team_id' => Team::factory()->status($status)->create(['league_id' => $tournament->league_id])->id,
        ]);
    }

    public function test_list_returns_each_team_status_and_the_counters(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        foreach (range(1, 5) as $ignored) {
            $this->enroll($tournament);
        }
        $this->enroll($tournament, TeamStatus::BANNED);
        // No cuentan: baja lógica y estado withdrawn.
        $this->enroll($tournament)->delete();
        Registration::factory()->status(RegistrationStatus::WITHDRAWN)->create(['tournament_id' => $tournament->id]);

        $response = $this->getJson($this->registrationsUrl($league, $tournament).'?per_page=2&search=zzz', $header)->assertOk();
        $response->assertJsonPath('summary', ['registered' => 6, 'banned' => 1]);

        $all = $this->getJson($this->registrationsUrl($league, $tournament).'?per_page=50', $header)->assertOk();
        $all->assertJsonPath('summary', ['registered' => 6, 'banned' => 1])->assertJsonPath('meta.total', 7);
        $statuses = array_count_values(array_column(array_column($all->json('data'), 'team'), 'status'));
        $this->assertSame(1, $statuses['banned']);
    }

    public function test_banned_teams_do_not_count_to_start_the_tournament(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->enroll($tournament);
        $this->enroll($tournament, TeamStatus::BANNED);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $this->patchJson($url, ['status' => 'in_progress'], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', 'El torneo necesita al menos 2 equipos inscritos y no vetados para iniciar.');

        $this->enroll($tournament);

        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_banning_and_reactivating_keeps_the_registration(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->enroll($tournament);
        $url = "/api/leagues/{$league->id}/teams/{$registration->team_id}/status";

        $this->patchJson($url, ['status' => 'banned'], $header)->assertOk();
        $this->assertNotSoftDeleted('registrations', ['id' => $registration->id, 'status' => 'active']);

        $this->patchJson($url, ['status' => 'active'], $header)->assertOk();
        $this->assertNotSoftDeleted('registrations', ['id' => $registration->id, 'status' => 'active']);
        $this->getJson($this->registrationsUrl($league, $tournament), $header)->assertJsonPath('summary.banned', 0)->assertJsonPath('data.0.id', $registration->id);
    }

    public function test_a_banned_team_registration_can_be_removed_in_planning(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->enroll($tournament, TeamStatus::BANNED);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNoContent();
    }

    public function test_schedule_rule_rejects_banned_teams_enrolled(): void
    {
        $tournament = Tournament::factory()->create();
        $service = app(RegistrationServiceInterface::class);
        $this->enroll($tournament);

        $service->assertNoBannedTeams($tournament);
        $this->enroll($tournament, TeamStatus::BANNED);

        $this->expectException(CustomErrorException::class);
        $this->expectExceptionMessage(Message::TOURNAMENT_SCHEDULE_HAS_BANNED_TEAMS);
        $service->assertNoBannedTeams($tournament);
    }
}
