<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveRegistrationTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    private function registrationIn(Tournament $tournament): Registration
    {
        return Registration::factory()->create([
            'tournament_id' => $tournament->id,
            'team_id' => Team::factory()->create(['league_id' => $tournament->league_id])->id,
        ]);
    }

    public function test_removes_the_registration_with_a_soft_delete(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->registrationIn($tournament);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNoContent();

        $this->assertSoftDeleted('registrations', ['id' => $registration->id]);
    }

    public function test_rejects_removing_when_the_tournament_is_not_in_planning(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->registrationIn($tournament);

        foreach ([TournamentStatus::IN_PROGRESS, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();

            $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)
                ->assertStatus(422)
                ->assertJsonPath('error', Message::REGISTRATION_REMOVE_ONLY_PLANNING);
        }

        $this->assertNotSoftDeleted('registrations', ['id' => $registration->id]);
    }

    public function test_a_registration_of_another_tournament_or_league_is_404(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $sibling = Tournament::factory()->create(['league_id' => $league->id]);
        $foreign = $this->registrationIn(Tournament::factory()->create());

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$this->registrationIn($sibling)->id}"), [], $header)->assertNotFound();
        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$foreign->id}"), [], $header)->assertNotFound();
        $this->deleteJson($this->registrationsUrl($league, $tournament, '/999999'), [], $header)->assertNotFound();
    }

    public function test_a_removed_registration_is_404(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->registrationIn($tournament);
        $registration->delete();

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNotFound();
    }
}
