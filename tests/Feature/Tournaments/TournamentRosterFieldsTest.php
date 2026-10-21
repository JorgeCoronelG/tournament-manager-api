<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Message;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rosters\InteractsWithRosters;
use Tests\TestCase;

class TournamentRosterFieldsTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Copa', 'gender' => 'mixed', 'start_date' => '2026-11-01'], $overrides);
    }

    public function test_creates_with_the_new_fields_and_they_default_to_null(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/tournaments";

        $this->postJson($url, $this->payload(['max_roster_size' => 18, 'min_matches_for_playoffs' => 5]), $header)
            ->assertCreated()->assertJsonPath('max_roster_size', 18)->assertJsonPath('min_matches_for_playoffs', 5);
        $this->postJson($url, $this->payload(['name' => 'Otra']), $header)
            ->assertCreated()->assertJsonPath('max_roster_size', null)->assertJsonPath('min_matches_for_playoffs', null);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalid(): array
    {
        return [
            'roster zero' => ['max_roster_size', 0],
            'roster negative' => ['max_roster_size', -1],
            'roster text' => ['max_roster_size', 'abc'],
            'matches zero' => ['min_matches_for_playoffs', 0],
            'matches negative' => ['min_matches_for_playoffs', -3],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_zero_negative_or_non_numeric_values_on_create_and_edit(string $field, mixed $value): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->postJson("/api/leagues/{$league->id}/tournaments", $this->payload([$field => $value]), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$field]]);
        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload([$field => $value]), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$field]]);
    }

    public function test_detail_and_list_show_the_fields(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id, 'max_roster_size' => 15, 'min_matches_for_playoffs' => 4]);

        $this->getJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $header)
            ->assertOk()->assertJsonPath('max_roster_size', 15)->assertJsonPath('min_matches_for_playoffs', 4);
        $this->getJson("/api/leagues/{$league->id}/tournaments", $header)
            ->assertOk()->assertJsonPath('data.0.max_roster_size', 15)->assertJsonPath('data.0.min_matches_for_playoffs', 4);
    }

    public function test_can_be_edited_in_planning_and_in_progress_until_the_playoffs_start(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id, 'gender' => 'mixed', 'start_date' => '2026-11-01', 'min_age' => null, 'max_age' => null]);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->putJson($url, $this->payload(['max_roster_size' => 20]), $header)->assertOk()->assertJsonPath('max_roster_size', 20);

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->putJson($url, $this->payload(['max_roster_size' => 22, 'min_matches_for_playoffs' => 6]), $header)
            ->assertOk()->assertJsonPath('max_roster_size', 22)->assertJsonPath('min_matches_for_playoffs', 6);

        $this->startPlayoffs($tournament);

        $this->putJson($url, $this->payload(['max_roster_size' => 25, 'min_matches_for_playoffs' => 6]), $header)
            ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_PLAYOFFS_STARTED_LOCKED);
        $this->putJson($url, $this->payload(['name' => 'Renombrado', 'max_roster_size' => 22, 'min_matches_for_playoffs' => 6]), $header)
            ->assertOk()->assertJsonPath('name', 'Renombrado');
        $this->assertSame(22, $tournament->fresh()->max_roster_size);
    }

    public function test_lowering_the_max_below_a_team_roster_is_rejected_naming_the_biggest_team(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id, 'gender' => 'mixed', 'start_date' => '2026-11-01', 'min_age' => null, 'max_age' => null]);
        [$small] = $this->registrationWithManager($tournament);
        [$big] = $this->registrationWithManager($tournament);
        $big->team->forceFill(['name' => 'Los Grandes'])->save();
        $this->rosterRow($small, $this->player(), RosterStatus::ACTIVE, 1);
        foreach ([1, 2] as $jersey) {
            $this->rosterRow($big, $this->player(), RosterStatus::ACTIVE, $jersey);
        }
        $this->rosterRow($big, $this->player(), RosterStatus::PENDING, 3);
        // Cerradas no cuentan.
        $this->rosterRow($big, $this->player(), RosterStatus::REJECTED, 4);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->putJson($url, $this->payload(['max_roster_size' => 2]), $header)
            ->assertStatus(422)
            ->assertJsonPath('error', 'No se puede fijar el máximo en 2: el equipo «Los Grandes» ya tiene 3 jugadores (activos y pendientes).');

        $this->putJson($url, $this->payload(['max_roster_size' => 3]), $header)->assertOk();
        $this->putJson($url, $this->payload(['max_roster_size' => null]), $header)->assertOk();
    }
}
