<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveTournamentTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    public function test_creates_a_tournament_in_planning_with_league_from_the_route(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $other = League::factory()->create();

        $response = $this->postJson(
            "/api/leagues/{$league->id}/tournaments",
            $this->payload(['league_id' => $other->id, 'status' => 'finished']),
            $header
        )->assertCreated()
            ->assertJsonPath('league_id', $league->id)
            ->assertJsonPath('status', 'planning')
            ->assertJsonPath('gender', 'male')
            ->assertJsonPath('start_date', '2026-11-01')
            ->assertJsonPath('min_age', 18);

        $this->assertDatabaseHas('tournaments', ['id' => $response->json('id'), 'league_id' => $league->id, 'status' => 'planning']);
    }

    public function test_ages_are_optional_and_past_dates_are_allowed(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", [
            'name' => 'Histórico', 'gender' => 'mixed', 'start_date' => '2020-01-01',
        ], $header)->assertCreated()->assertJsonPath('min_age', null)->assertJsonPath('max_age', null);
    }

    public function test_name_must_be_unique_within_the_league_ignoring_case_and_spaces(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->create(['league_id' => $league->id, 'name' => 'Copa Apertura']);
        $url = "/api/leagues/{$league->id}/tournaments";

        $this->postJson($url, $this->payload(['name' => '  copa APERTURA ']), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);

        // Permitido en otra liga y cuando el repetido está eliminado.
        $otherLeague = League::factory()->create(['admin_user_id' => $league->admin_user_id]);
        $this->postJson("/api/leagues/{$otherLeague->id}/tournaments", $this->payload(), $header)
            ->assertCreated();
        Tournament::query()->where('league_id', $league->id)->delete();
        $this->postJson($url, $this->payload(), $header)->assertCreated();
    }

    public function test_name_of_a_finished_tournament_can_be_reused(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id, 'name' => 'Copa Apertura']);
        $url = "/api/leagues/{$league->id}/tournaments";

        $this->postJson($url, $this->payload(), $header)->assertCreated();
        // El nuevo está activo, así que ya no se puede repetir.
        $this->postJson($url, $this->payload(), $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
    }

    public function test_update_allows_keeping_its_own_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id, 'name' => 'Copa Apertura']);

        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload(['max_age' => 50]), $header)
            ->assertOk()->assertJsonPath('max_age', 50);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['name' => null], 'name'],
            'long name' => [['name' => str_repeat('a', 151)], 'name'],
            'bad gender' => [['gender' => 'other'], 'gender'],
            'missing gender' => [['gender' => null], 'gender'],
            'min below 18' => [['min_age' => 17], 'min_age'],
            'max above 99' => [['max_age' => 100], 'max_age'],
            'max below 18' => [['max_age' => 10], 'max_age'],
            'non integer age' => [['min_age' => 'abc'], 'min_age'],
            'inverted ages' => [['min_age' => 40, 'max_age' => 30], 'min_age'],
            'bad date format' => [['start_date' => '01/11/2026'], 'start_date'],
            'impossible date' => [['start_date' => '2026-02-30'], 'start_date'],
            'missing date' => [['start_date' => null], 'start_date'],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     *
     * @param  array<string, mixed>  $override
     */
    public function test_rejects_invalid_payloads(array $override, string $field): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->postJson("/api/leagues/{$league->id}/tournaments", $this->payload($override), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$field]]);
        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload($override), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$field]]);
    }

    public function test_equal_ages_are_valid(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", $this->payload(['min_age' => 30, 'max_age' => 30]), $header)
            ->assertCreated();
    }

    public function test_update_in_planning_changes_every_field_and_ignores_status(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload([
            'name' => 'Nuevo', 'gender' => 'female', 'min_age' => 20, 'max_age' => null,
            'start_date' => '2027-01-15', 'status' => 'finished', 'league_id' => 999,
        ]), $header)->assertOk()
            ->assertJsonPath('name', 'Nuevo')
            ->assertJsonPath('gender', 'female')
            ->assertJsonPath('min_age', 20)
            ->assertJsonPath('max_age', null)
            ->assertJsonPath('start_date', '2027-01-15')
            ->assertJsonPath('status', 'planning')
            ->assertJsonPath('league_id', $league->id);
    }

    public function test_update_in_progress_only_changes_the_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id, 'gender' => 'male', 'min_age' => 18, 'max_age' => 40, 'start_date' => '2026-11-01',
        ]);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->putJson($url, $this->payload(['name' => 'Renombrado']), $header)
            ->assertOk()->assertJsonPath('name', 'Renombrado')->assertJsonPath('status', 'in_progress');

        foreach (['gender' => 'female', 'min_age' => 19, 'max_age' => 41, 'start_date' => '2026-12-01'] as $field => $value) {
            $this->putJson($url, $this->payload([$field => $value]), $header)
                ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_IN_PROGRESS_ONLY_NAME);
        }
        $this->assertSame('Renombrado', $tournament->fresh()->name);
    }

    public function test_finished_tournament_cannot_be_edited(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);

        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload(), $header)
            ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_FINISHED_NOT_EDITABLE);
    }

    public function test_shows_a_tournament(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $header)
            ->assertOk()->assertJsonPath('id', $tournament->id);
    }
}
