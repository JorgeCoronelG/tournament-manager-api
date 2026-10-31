<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class WalkoverGoalsTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(Tournament $tournament, ?int $goals): array
    {
        return array_filter([
            'name' => $tournament->name,
            'gender' => $tournament->gender->value,
            'start_date' => $tournament->start_date->format('Y-m-d'),
            'walkover_goals' => $goals,
        ], fn (mixed $value) => $value !== null);
    }

    public function test_defaults_to_3_and_appears_in_the_resource(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", ['name' => 'Torneo X', 'gender' => 'mixed', 'start_date' => '2026-12-01'], $header)
            ->assertCreated()->assertJsonPath('walkover_goals', 3);
    }

    public function test_can_be_set_on_creation_within_1_and_99(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $body = ['name' => 'Torneo Y', 'gender' => 'mixed', 'start_date' => '2026-12-01'];

        $this->postJson("/api/leagues/{$league->id}/tournaments", $body + ['walkover_goals' => 5], $header)->assertCreated()->assertJsonPath('walkover_goals', 5);

        foreach ([0, 100, 'abc'] as $value) {
            $this->postJson("/api/leagues/{$league->id}/tournaments", ['name' => 'Torneo Z'] + $body + ['walkover_goals' => $value], $header)
                ->assertStatus(422)->assertJsonStructure(['error' => ['walkover_goals']]);
        }
    }

    public function test_is_editable_in_planning_and_in_progress(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->putJson($url, $this->payload($tournament, 2), $header)->assertOk()->assertJsonPath('walkover_goals', 2);

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        $this->putJson($url, $this->payload($tournament->refresh(), 4), $header)->assertOk()->assertJsonPath('walkover_goals', 4);
        $this->putJson($url, $this->payload($tournament->refresh(), 0), $header)->assertStatus(422);
    }

    public function test_changing_it_does_not_modify_registered_walkovers(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertOk();

        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", $this->payload($tournament->refresh(), 1), $header)->assertOk();

        $this->assertSame(3, $match->fresh()->away_score);
    }

    public function test_is_not_editable_in_the_playoffs_nor_when_finished(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();
        $this->putJson($url, $this->payload($tournament->refresh(), 2), $header)->assertStatus(422)
            ->assertJsonPath('error', Message::TOURNAMENT_WALKOVER_GOALS_LOCKED);
        $this->putJson($url, $this->payload($tournament->refresh(), 3), $header)->assertOk();

        $tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();
        $this->putJson($url, $this->payload($tournament->refresh(), 2), $header)->assertStatus(422)
            ->assertJsonPath('error', Message::TOURNAMENT_FINISHED_NOT_EDITABLE);
    }
}
