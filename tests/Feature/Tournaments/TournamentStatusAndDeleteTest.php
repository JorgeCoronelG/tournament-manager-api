<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentStatusAndDeleteTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    public function test_advances_forward_one_step_at_a_time(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk()->assertJsonPath('status', 'in_progress');
        $this->patchJson($url, ['status' => 'finished'], $header)->assertOk()->assertJsonPath('status', 'finished');
        $this->assertSame(TournamentStatus::FINISHED, $tournament->fresh()->status);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'skip planning to finished' => ['planning', 'finished'],
            'same status' => ['planning', 'planning'],
            'backwards from in_progress' => ['in_progress', 'planning'],
            'in_progress to same' => ['in_progress', 'in_progress'],
            'backwards from finished' => ['finished', 'in_progress'],
            'finished to planning' => ['finished', 'planning'],
        ];
    }

    /**
     * @dataProvider invalidTransitions
     */
    public function test_rejects_invalid_transitions_with_422(string $from, string $to): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::from($from))->create(['league_id' => $league->id]);

        $response = $this->patchJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/status", ['status' => $to], $header)
            ->assertStatus(422);

        $this->assertStringContainsString('No se puede pasar el torneo', $response->json('error'));
        $this->assertSame($from, $tournament->fresh()->status->value);
    }

    public function test_status_must_be_a_valid_code(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $this->patchJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
        $this->patchJson($url, ['status' => 'bogus'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
    }

    public function test_deletes_only_in_planning_and_then_responds_404(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->deleteJson($url, [], $header)->assertNoContent();
        $this->assertSoftDeleted('tournaments', ['id' => $tournament->id]);
        $this->getJson($url, $header)->assertStatus(404);
        $this->deleteJson($url, [], $header)->assertStatus(404);
    }

    public function test_cannot_delete_in_progress_or_finished(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        foreach ([TournamentStatus::IN_PROGRESS, TournamentStatus::FINISHED] as $status) {
            $tournament = Tournament::factory()->status($status)->create(['league_id' => $league->id]);

            $this->deleteJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", [], $header)
                ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_DELETE_ONLY_PLANNING);
            $this->assertNotSoftDeleted('tournaments', ['id' => $tournament->id]);
        }
    }
}
