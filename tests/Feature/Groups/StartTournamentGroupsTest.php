<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StartTournamentGroupsTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function statusUrl(Tournament $tournament): string
    {
        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/status";
    }

    public function test_every_regular_group_needs_at_least_two_teams(): void
    {
        [, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 3);
        $this->makeGroup($tournament, 'Grupo B');
        $this->teamsIn($tournament, $tournament->groups()->where('name', 'Grupo B')->firstOrFail(), 1);

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)
            ->assertStatus(422)->assertJsonPath('error', 'El grupo Grupo B necesita al menos 2 equipos.');
        $this->assertSame(TournamentStatus::PLANNING, $tournament->fresh()->status);

        $this->teamsIn($tournament, $tournament->groups()->where('name', 'Grupo B')->firstOrFail(), 1);

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_an_empty_extra_group_blocks_the_start(): void
    {
        [, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 2);
        $this->makeGroup($tournament, 'Vacío');

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::getMessageGroupNeedsTeams('Vacío'));
    }

    public function test_a_playoff_cannot_ask_for_more_positions_than_the_group_has(): void
    {
        [, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $this->teamsIn($tournament, $group, 3);
        $playoff = $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);
        DB::table('playoff_sources')->insert([
            'playoff_group_id' => $playoff->id, 'source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', 'La liguilla Liguilla pide hasta la posición 4 del grupo Fase regular, que solo tiene 3 equipos.');

        $this->teamsIn($tournament, $group, 1);

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_starts_normally_without_playoffs(): void
    {
        [, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 2);

        $this->patchJson($this->statusUrl($tournament), ['status' => 'in_progress'], $header)
            ->assertOk()->assertJsonPath('status', 'in_progress');
    }
}
