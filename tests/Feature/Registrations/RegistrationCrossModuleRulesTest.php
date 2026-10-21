<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationCrossModuleRulesTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    public function test_a_team_with_active_registrations_cannot_be_deleted(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);

        $this->deleteJson("/api/leagues/{$league->id}/teams/{$team->id}", [], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', 'El equipo está inscrito en un torneo. Quítalo del torneo primero.');

        $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
    }

    public function test_a_team_can_be_deleted_once_its_registration_is_removed_or_withdrawn(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $removed = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $removed->id, 'tournament_id' => $tournament->id])->delete();
        $withdrawn = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->status(RegistrationStatus::WITHDRAWN)->create(['team_id' => $withdrawn->id, 'tournament_id' => $tournament->id]);

        $this->deleteJson("/api/leagues/{$league->id}/teams/{$removed->id}", [], $header)->assertNoContent();
        $this->deleteJson("/api/leagues/{$league->id}/teams/{$withdrawn->id}", [], $header)->assertNoContent();
    }

    public function test_deleting_a_tournament_removes_its_registrations_only(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $other = Tournament::factory()->create(['league_id' => $league->id]);
        $mine = Registration::factory()->create(['team_id' => Team::factory()->create(['league_id' => $league->id])->id, 'tournament_id' => $tournament->id]);
        $theirs = Registration::factory()->create(['team_id' => Team::factory()->create(['league_id' => $league->id])->id, 'tournament_id' => $other->id]);

        $this->deleteJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", [], $header)->assertNoContent();

        $this->assertSoftDeleted('registrations', ['id' => $mine->id]);
        $this->assertNotSoftDeleted('registrations', ['id' => $theirs->id]);
    }

    public function test_the_message_constant_matches_the_spec(): void
    {
        $this->assertSame('El equipo está inscrito en un torneo. Quítalo del torneo primero.', Message::TEAM_HAS_ACTIVE_REGISTRATIONS);
    }
}
