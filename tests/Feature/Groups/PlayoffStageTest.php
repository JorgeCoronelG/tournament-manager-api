<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlayoffStageTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    public function test_status_flow_with_and_without_playoffs(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $this->teamsIn($tournament, $group, 4);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $this->patchJson($url, ['status' => 'playoffs'], $header)->assertStatus(422);
        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk();
        $this->patchJson($url, ['status' => 'planning'], $header)->assertStatus(422);
        $this->patchJson($url, ['status' => 'playoffs'], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::TOURNAMENT_TO_PLAYOFFS_USE_CLOSE);
        $this->patchJson($url, ['status' => 'finished'], $header)->assertOk();
    }

    public function test_in_progress_with_playoffs_cannot_finish_directly_but_playoffs_can(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $this->teamsIn($tournament, $group, 4);
        $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);
        $url = "/api/leagues/{$league->id}/tournaments/{$tournament->id}/status";

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->patchJson($url, ['status' => 'finished'], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::TOURNAMENT_FINISH_HAS_PLAYOFFS);

        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();
        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertStatus(422);
        $this->patchJson($url, ['status' => 'finished'], $header)->assertOk();
    }

    public function test_playoffs_status_is_accepted_by_the_list_filter(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();

        $this->getJson("/api/leagues/{$league->id}/tournaments?status=playoffs", $header)->assertOk()->assertJsonPath('data.0.status', 'playoffs');
    }

    public function test_playoffs_tournament_only_allows_renaming(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();

        $this->putJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}", [
            'name' => 'Otro nombre', 'gender' => 'female', 'start_date' => $tournament->start_date->format('Y-m-d'),
        ], $header)->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_IN_PROGRESS_ONLY_NAME);
    }

    public function test_playoffs_can_be_managed_in_progress_but_not_after(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $this->teamsIn($tournament, $a, 4);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $url = $this->groupsUrl($league, $tournament);

        $id = $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Liguilla',
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->assertJsonPath('start_date', null)->assertJsonPath('final_date', null)
            ->assertJsonPath('rounds_detail.0.date', null)->assertJsonPath('editable', true)->json('id');

        $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Otra',
            'sources' => [['source_group_id' => $a->id, 'position_start' => 5, 'position_end' => 6]],
        ], $header)->assertStatus(422)->assertJsonPath('error', fn ($error) => str_contains(json_encode($error, JSON_UNESCAPED_UNICODE), 'solo tiene 4 equipos'));

        $this->patchJson("{$url}/{$id}", ['name' => 'Renombrada'], $header)->assertOk();

        DB::table('matches')->insert($this->matchRow($tournament, $id, $a));
        $this->patchJson("{$url}/{$id}", ['name' => 'X'], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFF_HAS_MATCHES);
        $this->deleteJson("{$url}/{$id}", [], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFF_HAS_MATCHES);
        $this->getJson($url, $header)->assertJsonPath('groups.1.editable', false);

        foreach ([TournamentStatus::PLAYOFFS, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();
            $this->patchJson("{$url}/{$id}", ['name' => 'X'], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFFS_LOCKED);
            $this->deleteJson("{$url}/{$id}", [], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFFS_LOCKED);
            $this->postJson($url, ['type' => 'playoff', 'name' => 'Y', 'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 2]]], $header)
                ->assertStatus(422)->assertJsonPath('error', Message::PLAYOFFS_LOCKED);
        }
    }

    public function test_round_dates_need_start_date_and_must_start_on_it(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $url = $this->groupsUrl($league, $tournament);
        $body = fn (array $extra) => $extra + [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_dates' => ['2026-12-01', '2026-12-08'],
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ];

        $this->postJson($url, $body([]), $header)->assertStatus(422)->assertJsonPath('error.round_dates.0', Message::PLAYOFF_ROUND_DATES_NEED_START);
        $this->postJson($url, $body(['start_date' => '2026-12-02']), $header)->assertStatus(422)->assertJsonPath('error.round_dates.0', Message::PLAYOFF_ROUND_DATES_FIRST_START);
        $this->postJson($url, $body(['start_date' => '2026-12-01']), $header)->assertCreated();
    }

    public function test_clearing_start_date_clears_round_dates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $url = $this->groupsUrl($league, $tournament);
        $id = $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-01', 'round_dates' => ['2026-12-01', '2026-12-08'],
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');

        $this->patchJson("{$url}/{$id}", ['start_date' => null], $header)->assertOk()
            ->assertJsonPath('round_dates', null)->assertJsonPath('final_date', null);
        $this->getJson($url, $header)->assertJsonPath('finals_aligned', null);
    }

    public function test_starting_without_playoffs_is_allowed(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 2);

        $this->patchJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/status", ['status' => 'in_progress'], $header)->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function matchRow(Tournament $tournament, int $groupId, TournamentGroup $regular): array
    {
        $registrations = $tournament->registrations()->where('group_id', $regular->id)->orderBy('id')->take(2)->pluck('id');

        return [
            'tournament_id' => $tournament->id, 'group_id' => $groupId, 'phase' => 'semifinal',
            'home_registration_id' => $registrations[0], 'away_registration_id' => $registrations[1],
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
