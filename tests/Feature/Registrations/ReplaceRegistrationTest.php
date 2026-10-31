<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Events\TeamReplaced;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class ReplaceRegistrationTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function replaceUrl(Tournament $tournament, Registration $registration): string
    {
        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registration->id}/replace";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function newTeam(League $league, array $attributes = []): Team
    {
        return Team::factory()->create(['league_id' => $league->id, ...$attributes]);
    }

    public function test_replaces_the_team_keeping_the_registration_its_matches_and_group(): void
    {
        Event::fake([TeamReplaced::class]);
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $oldTeamId = $registration->team_id;
        $groupId = $registration->group_id;
        $matches = GameMatch::query()->count();
        $active = Roster::factory()->forRegistration($registration)->status(RosterStatus::ACTIVE)->create(['player_id' => Player::factory()->create()->id, 'jersey_number' => 5]);
        $pending = Roster::factory()->forRegistration($registration)->status(RosterStatus::PENDING)->create(['player_id' => Player::factory()->create()->id, 'jersey_number' => 6]);
        $replacement = $this->newTeam($league);

        $this->postJson($this->replaceUrl($tournament, $registration), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertOk()
            ->assertJsonPath('registration.id', $registration->id)
            ->assertJsonPath('registration.team.id', $replacement->id)
            ->assertJsonPath('warnings.0', Message::REGISTRATION_REPLACE_ROSTER_WARNING);

        $fresh = $registration->fresh();
        $this->assertSame($replacement->id, $fresh->team_id);
        $this->assertSame($groupId, $fresh->group_id);
        $this->assertSame(RegistrationStatus::ACTIVE, $fresh->status);
        $this->assertSame($matches, GameMatch::query()->count());
        $this->assertSame(RosterStatus::RELEASED, $active->fresh()->status);
        $this->assertSame(RosterStatus::RELEASED, $pending->fresh()->status);
        $this->assertDatabaseHas('registration_team_changes', [
            'registration_id' => $registration->id, 'old_team_id' => $oldTeamId, 'new_team_id' => $replacement->id,
            'reason' => 'Cambio de equipo', 'changed_by_user_id' => $league->admin_user_id,
        ]);
        Event::assertDispatched(TeamReplaced::class, fn (TeamReplaced $event) => $event->oldTeamId === $oldTeamId && $event->newTeamId === $replacement->id);
    }

    public function test_the_new_team_keeps_the_points_of_the_registration(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $match = GameMatch::query()->where('home_registration_id', $teams[0]->id)->firstOrFail();
        $this->patchJson($this->base($league, $tournament)."/matches/{$match->id}/result", ['home_score' => 2, 'away_score' => 0], $header)->assertOk();
        $replacement = $this->newTeam($league, ['name' => 'Equipo Nuevo']);

        $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertOk();

        $rows = collect($this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->json('groups.0.rows'))->keyBy('registration_id');
        $this->assertSame(3, $rows[$teams[0]->id]['points']);
        $this->assertSame('Equipo Nuevo', $rows[$teams[0]->id]['team_name']);
    }

    public function test_responds_422_in_the_playoffs_and_outside_the_running_tournament(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->inPlayoffs([1, 1]);
        $replacement = $this->newTeam($league);

        $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => 'En liguilla no se puede reemplazar un equipo; solo darlo de baja.']);

        foreach ([TournamentStatus::PLANNING, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();

            $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertStatus(422)
                ->assertJsonPath('error', Message::REGISTRATION_REPLACE_ONLY_IN_PROGRESS);
        }

        $this->assertNotSame($replacement->id, $teams[0]->fresh()->team_id);
    }

    public function test_replacing_a_withdrawn_registration_reactivates_it_and_keeps_its_pending_matches(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $matches = GameMatch::query()->where('home_registration_id', $registration->id)->orWhere('away_registration_id', $registration->id)->orderBy('id')->get();
        $played = $matches->first();
        $played->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 2, 'away_score' => 1])->save();
        $field = Field::factory()->create(['league_id' => $league->id]);
        $pending = $matches->get(1);
        $pending->forceFill(['match_datetime' => now()->addDays(4)->setTime(9, 0), 'field_id' => $field->id])->save();
        $before = $pending->fresh()->only(['match_datetime', 'field_id']);

        $this->postJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/registrations/{$registration->id}/withdraw", ['reason' => 'Abandonó el torneo'], $header)->assertOk();
        $replacement = $this->newTeam($league);

        $this->postJson($this->replaceUrl($tournament, $registration), ['team_id' => $replacement->id, 'reason' => 'Entra otro equipo'], $header)->assertOk()
            ->assertJsonPath('registration.status', 'active')
            ->assertJsonPath('registration.withdrawn_at', null)
            ->assertJsonPath('registration.withdrawn_reason', null);

        $fresh = $registration->fresh();
        $this->assertSame(RegistrationStatus::ACTIVE, $fresh->status);
        $this->assertSame($replacement->id, $fresh->team_id);
        $this->assertNull($fresh->withdrawn_by_user_id);
        $this->assertEquals($before, $pending->fresh()->only(['match_datetime', 'field_id']));
        $this->assertSame(MatchStatus::SCHEDULED, $pending->fresh()->status);
        $this->assertSame(2, $played->fresh()->home_score);

        $rows = collect($this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->json('groups.0.rows'))->keyBy('registration_id');
        $this->assertFalse($rows[$registration->id]['withdrawn']);
        $this->assertSame($replacement->id, $rows[$registration->id]['team_id']);

        $this->patchJson($this->base($league, $tournament)."/matches/{$pending->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertOk();
    }

    public function test_rejects_teams_that_cannot_replace(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $teams[1]->team->forceFill(['name' => 'Los Repetidos'])->save();

        $cases = [
            'other league' => $this->newTeam(League::factory()->create())->id,
            'banned' => $this->newTeam($league, ['status' => TeamStatus::BANNED])->id,
            'already registered' => $teams[2]->team_id,
            'repeated name' => $this->newTeam($league, ['name' => 'LOS REPETIDOS'])->id,
            'missing' => 999999,
        ];

        foreach ($cases as $teamId) {
            $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $teamId, 'reason' => 'Cambio de equipo'], $header)->assertStatus(422)
                ->assertJsonStructure(['error' => ['team_id']]);
        }

        $this->assertSame(0, DB::table('registration_team_changes')->count());
    }

    public function test_permissions(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $replacement = $this->newTeam($league);
        $foreign = Tournament::factory()->create();
        $foreignTeam = Registration::factory()->create(['tournament_id' => $foreign->id]);

        $this->postJson($this->replaceUrl($foreign, $foreignTeam), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertStatus(403);
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$foreign->id}/registrations/{$teams[0]->id}/replace", ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $header)->assertStatus(404);
        $this->app->make('auth')->forgetGuards();
        $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'])->assertStatus(401);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->postJson($this->replaceUrl($tournament, $teams[0]), ['team_id' => $replacement->id, 'reason' => 'Cambio de equipo'], $this->headerForRole($role))->assertStatus(403);
        }
    }
}
