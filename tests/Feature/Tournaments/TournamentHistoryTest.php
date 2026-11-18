<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Role;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentHistoryTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    private function finished(League $league, string $name, string $start, TournamentStatus $status = TournamentStatus::FINISHED): Tournament
    {
        $tournament = Tournament::factory()->create(['league_id' => $league->id, 'name' => $name, 'start_date' => $start]);
        $tournament->forceFill(['status' => $status])->save();

        return $tournament;
    }

    public function test_league_without_finished_tournaments_returns_empty_list_and_years(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->finished($league, 'En curso', '2026-01-01', TournamentStatus::IN_PROGRESS);

        $this->getJson("/api/leagues/{$league->id}/tournaments/history", $header)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->getJson("/api/leagues/{$league->id}/tournaments/history/years", $header)->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_years_are_distinct_descending_and_ignore_filters(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->finished($league, 'A', '2026-02-01');
        $this->finished($league, 'B', '2026-08-01');
        $this->finished($league, 'C', '2027-01-01');
        $this->finished($league, 'D', '2025-01-01');
        $this->finished($league, 'E', '2030-01-01', TournamentStatus::PLAYOFFS);

        $this->getJson("/api/leagues/{$league->id}/tournaments/history/years?year=2026&search=A", $header)
            ->assertOk()->assertExactJson(['data' => [2027, 2026, 2025]]);
    }

    public function test_lists_only_finished_most_recent_first_with_shape(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $old = $this->finished($league, 'Viejo', '2025-03-01');
        $new = $this->finished($league, 'Nuevo', '2026-03-01');
        $this->finished($league, 'Liguilla', '2026-04-01', TournamentStatus::PLAYOFFS);
        $this->finished($league, 'En curso', '2026-05-01', TournamentStatus::IN_PROGRESS);

        $response = $this->getJson("/api/leagues/{$league->id}/tournaments/history", $header)->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertSame([$new->id, $old->id], $response->json('data.*.id'));
        $this->assertSame(['id', 'name', 'start_date', 'end_date', 'teams_count', 'playoffs_count'], array_keys($response->json('data.0')));
        $this->assertNull($response->json('data.0.end_date'));
    }

    public function test_filters_by_year_and_search_together_ignoring_case(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $hit = $this->finished($league, 'Copa Apertura', '2026-02-01');
        $this->finished($league, 'Copa Apertura', '2025-02-01');
        $this->finished($league, 'Liga Clausura', '2026-06-01');

        $this->getJson("/api/leagues/{$league->id}/tournaments/history?year=2026&search=apert", $header)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $hit->id);
        $this->getJson("/api/leagues/{$league->id}/tournaments/history?year=2026", $header)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/leagues/{$league->id}/tournaments/history?year=abc", $header)->assertStatus(400);
    }

    public function test_counts_active_teams_playoffs_and_end_date(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->finished($league, 'Con datos', '2026-02-01');
        $group = $tournament->groups()->firstOrFail();
        Registration::factory()->count(3)->create(['tournament_id' => $tournament->id, 'group_id' => $group->id]);
        Registration::factory()->create(['tournament_id' => $tournament->id, 'group_id' => $group->id, 'status' => 'withdrawn']);
        foreach (['Liguilla A', 'Liguilla B'] as $name) {
            TournamentGroup::query()->forceCreate(['tournament_id' => $tournament->id, 'name' => $name, 'type' => TournamentGroupType::PLAYOFF]);
        }
        $empty = $this->finished($league, 'Sin nada', '2025-02-01');

        $response = $this->getJson("/api/leagues/{$league->id}/tournaments/history", $header)->assertOk();

        $this->assertSame(3, $response->json('data.0.teams_count'));
        $this->assertSame(2, $response->json('data.0.playoffs_count'));
        $this->assertSame($empty->id, $response->json('data.1.id'));
        $this->assertSame(0, $response->json('data.1.teams_count'));
        $this->assertSame(0, $response->json('data.1.playoffs_count'));
    }

    public function test_a_league_does_not_see_tournaments_of_another(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $other = League::factory()->create();
        $this->finished($other, 'Ajeno', '2026-01-01');

        $this->getJson("/api/leagues/{$league->id}/tournaments/history", $header)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/leagues/{$other->id}/tournaments/history", $header)->assertForbidden();
    }

    public function test_only_superadmin_and_league_admin_can_read(): void
    {
        [$league] = $this->leagueWithAdminHeader();

        foreach ([Role::PLAYER, Role::REFEREE] as $role) {
            $this->getJson("/api/leagues/{$league->id}/tournaments/history", $this->otherUserHeader((string) $role->value))->assertForbidden();
        }
    }
}
