<?php

namespace Tests\Feature\Registrations;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\RosterStatus;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class RegistrationHistoryTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function replaceUrl(Tournament $tournament, Registration $registration): string
    {
        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registration->id}/replace";
    }

    public function test_removing_and_registering_again_keeps_the_deleted_row_as_history(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $first = Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$first->id}"), [], $header)->assertNoContent();
        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)->assertCreated();

        $this->assertSame(2, Registration::withTrashed()->where('team_id', $team->id)->count());
        $this->assertSoftDeleted('registrations', ['id' => $first->id]);
        $this->assertSame(1, Registration::query()->where('team_id', $team->id)->count());
        $this->assertSame($league->admin_user_id, Registration::onlyTrashed()->findOrFail($first->id)->removed_by_user_id);

        $second = Registration::query()->where('team_id', $team->id)->firstOrFail();
        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$second->id}"), [], $header)->assertNoContent();

        $this->assertSame(2, Registration::onlyTrashed()->where('team_id', $team->id)->count());
        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)->assertCreated();
    }

    public function test_removal_stores_who_and_when(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNoContent();

        $removed = Registration::onlyTrashed()->findOrFail($registration->id);
        $this->assertSame($league->admin_user_id, $removed->removed_by_user_id);
        $this->assertNotNull($removed->deleted_at);
    }

    public function test_replacing_by_a_team_with_a_deleted_row_in_the_tournament_works(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $incoming = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $incoming->id, 'tournament_id' => $tournament->id])->delete();

        $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $incoming->id, 'reason' => 'Cambio de equipo'], $header)
            ->assertOk()
            ->assertJsonPath('registration.team.id', $incoming->id);
    }

    public function test_replacing_by_a_team_with_a_live_row_is_rejected(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $teams[1]->team->forceFill(['league_id' => $league->id])->save();

        $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $teams[1]->team_id, 'reason' => 'Cambio de equipo'], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_id' => [Message::REGISTRATION_TEAM_ALREADY_REGISTERED]]]);
    }

    public function test_a_second_live_row_of_the_same_team_is_rejected_by_the_database(): void
    {
        [$league, $tournament] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $repository = app(RegistrationRepositoryInterface::class);
        $repository->register($tournament->id, $team->id);

        $this->expectException(QueryException::class);
        $repository->register($tournament->id, $team->id);
    }

    public function test_a_withdrawn_team_still_blocks_a_new_registration(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->withdrawn()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
            ->assertStatus(422)
            ->assertJson(['error' => ['team_ids.0' => [Message::REGISTRATION_TEAM_ALREADY_REGISTERED]]]);
        $this->assertSame(RegistrationStatus::WITHDRAWN, Registration::query()->where('team_id', $team->id)->firstOrFail()->status);
    }

    public function test_the_roster_of_the_deleted_row_is_kept(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $old = Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
        $roster = Roster::factory()->forRegistration($old)->status(RosterStatus::PENDING)->create(['player_id' => Player::factory()->create()->id, 'jersey_number' => 9]);

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$old->id}"), [], $header)->assertNoContent();
        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)->assertCreated();

        $this->assertSame($old->id, $roster->fresh()?->registration_id);
        $this->assertSame(0, Roster::query()->where('registration_id', '!=', $old->id)->count());
    }

    public function test_the_matches_of_the_deleted_row_are_kept(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $match = GameMatch::query()->where('home_registration_id', $teams[0]->id)->firstOrFail();
        $teams[0]->delete();

        $this->assertSame($teams[0]->id, $match->fresh()?->home_registration_id);
    }
}
