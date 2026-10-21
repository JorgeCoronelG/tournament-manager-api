<?php

namespace Tests\Feature\Sports;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Core\Enum\Message;
use App\Core\Enum\Sport;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Repositories\RegistrationRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Registrations\InteractsWithRegistrations;
use Tests\TestCase;

class SportTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    public function test_tournament_without_sport_defaults_to_football(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", [
            'name' => 'Apertura', 'gender' => 'mixed', 'start_date' => '2026-11-01',
        ], $header)
            ->assertCreated()
            ->assertJsonPath('sport', 'football')
            ->assertJsonPath('sport_label', 'Fútbol');

        $this->assertDatabaseHas('tournaments', ['name' => 'Apertura', 'sport' => 'football']);
    }

    public function test_team_without_sport_defaults_to_football(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres'], $header)
            ->assertCreated()
            ->assertJsonPath('sport', 'football')
            ->assertJsonPath('sport_label', 'Fútbol');

        $this->assertDatabaseHas('teams', ['name' => 'Tigres', 'sport' => 'football']);
    }

    public function test_invalid_sport_is_a_validation_error(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", [
            'name' => 'Apertura', 'sport' => 'tennis', 'gender' => 'mixed', 'start_date' => '2026-11-01',
        ], $header)->assertStatus(422)->assertJsonStructure(['error' => ['sport']]);

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres', 'sport' => 'tennis'], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['sport']]);
    }

    public function test_existing_rows_without_sport_are_football(): void
    {
        $tournament = Tournament::factory()->create();
        DB::table('tournaments')->where('id', $tournament->id)->update(['updated_at' => now()]);

        $row = DB::table('teams')->insertGetId([
            'league_id' => $tournament->league_id, 'name' => 'Viejo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(Sport::FOOTBALL, Tournament::query()->findOrFail($tournament->id)->sport);
        $this->assertSame(Sport::FOOTBALL, Team::query()->findOrFail($row)->sport);
    }

    public function test_registration_rejects_a_team_of_another_sport(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        // Solo existe un deporte: el repositorio devuelve un equipo que reporta uno distinto.
        $other = new class extends Team
        {
            public function getAttribute($key)
            {
                return $key === 'sport' ? 'other' : parent::getAttribute($key);
            }
        };
        $other->setRawAttributes($team->getAttributes());
        $other->exists = true;

        $repository = Mockery::mock(RegistrationRepository::class, [new Registration])->makePartial();
        $repository->shouldReceive('findTeamsInLeague')->andReturn(new Collection([$other->id => $other]));
        $this->app->instance(RegistrationRepositoryInterface::class, $repository);

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$team->id]], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['team_ids.0']]);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_tournament_sport_cannot_change_with_registered_teams(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
        $body = ['name' => $tournament->name, 'gender' => $tournament->gender->value, 'start_date' => $tournament->start_date->format('Y-m-d')];

        $this->putJson($url, $body + ['sport' => 'football'], $header)->assertOk()->assertJsonPath('sport', 'football');

        Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->putJson($url, $body + ['sport' => 'tennis'], $header)->assertStatus(422);
    }

    public function test_team_sport_update_keeps_current_when_omitted(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        $this->putJson("/api/leagues/{$league->id}/teams/{$team->id}", ['name' => 'Nuevo'], $header)
            ->assertOk()
            ->assertJsonPath('sport', 'football');
    }

    public function test_message_is_in_spanish(): void
    {
        $this->assertSame('El equipo y el torneo deben ser del mismo deporte.', Message::REGISTRATION_SPORT_MISMATCH);
    }
}
