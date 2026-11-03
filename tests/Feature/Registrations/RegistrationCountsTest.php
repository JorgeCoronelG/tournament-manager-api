<?php

namespace Tests\Feature\Registrations;

use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class RegistrationCountsTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function list(Tournament $tournament, array $header, string $query = ''): TestResponse
    {
        return $this->getJson("/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations{$query}", $header)->assertOk();
    }

    private function withdraw(Tournament $tournament, array $header, int $registrationId): void
    {
        $this->postJson("/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registrationId}/withdraw", ['reason' => 'Abandonó el torneo'], $header)->assertOk();
    }

    public function test_a_tournament_without_withdrawals_has_zero(): void
    {
        [, $tournament, $header] = $this->running(4);

        $this->list($tournament, $header)->assertJsonPath('meta.active_count', 4)->assertJsonPath('meta.withdrawn_count', 0);
    }

    public function test_counts_one_or_several_withdrawals(): void
    {
        [, $tournament, $header, $teams] = $this->running(4);

        $this->withdraw($tournament, $header, $teams[0]->id);
        $this->list($tournament, $header)->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);

        $this->withdraw($tournament, $header, $teams[1]->id);
        $this->list($tournament, $header)->assertJsonPath('meta.active_count', 2)->assertJsonPath('meta.withdrawn_count', 2)->assertJsonPath('meta.total', 4);
    }

    public function test_replacing_a_team_updates_the_counts(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $this->withdraw($tournament, $header, $teams[0]->id);
        $replace = fn (int $registrationId) => $this->postJson(
            "/api/leagues/{$league->id}/tournaments/{$tournament->id}/registrations/{$registrationId}/replace",
            ['team_id' => Team::factory()->create(['league_id' => $league->id])->id, 'reason' => 'Entra otro equipo'],
            $header
        )->assertOk();

        // Reemplazar un equipo activo no cambia los contadores.
        $replace($teams[1]->id);
        $this->list($tournament, $header)->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);

        // Reemplazar el que estaba en baja lo reactiva: una baja menos y una activa más.
        $replace($teams[0]->id);
        $this->list($tournament, $header)->assertJsonPath('meta.active_count', 4)->assertJsonPath('meta.withdrawn_count', 0);
    }

    public function test_the_counters_ignore_search_filters_pagination_and_skip_paging(): void
    {
        [, $tournament, $header, $teams] = $this->running(4);
        $teams[0]->team->forceFill(['name' => 'Unico Buscado'])->save();
        $this->withdraw($tournament, $header, $teams[1]->id);

        $searched = $this->list($tournament, $header, '?search=Unico');
        $searched->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);

        $this->list($tournament, $header, '?per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);
        $this->list($tournament, $header, '?status=withdrawn')->assertJsonCount(1, 'data')->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);
        $this->list($tournament, $header, '?skip_paging=true')->assertJsonCount(4, 'data')->assertJsonPath('meta.active_count', 3)->assertJsonPath('meta.withdrawn_count', 1);
    }
}
