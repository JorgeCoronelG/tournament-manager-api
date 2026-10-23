<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TournamentGroupType;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupAssignmentTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    public function test_moves_teams_between_groups_and_can_leave_them_without_group(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        [$one, $two, $three] = $this->teamsIn($tournament, $a, 3);

        $this->putJson($this->groupsUrl($league, $tournament, '/assignments'), ['assignments' => [
            ['registration_id' => $one->id, 'group_id' => $b->id],
            ['registration_id' => $two->id, 'group_id' => null],
        ]], $header)
            ->assertOk()
            ->assertJsonPath('groups.0.teams_count', 1)
            ->assertJsonPath('groups.1.teams_count', 1)
            ->assertJsonCount(1, 'unassigned');

        $this->assertSame($b->id, $one->fresh()->group_id);
        $this->assertNull($two->fresh()->group_id);
        $this->assertSame($a->id, $three->fresh()->group_id);
    }

    public function test_is_atomic_when_a_row_is_invalid(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        [$one] = $this->teamsIn($tournament, $a, 1);
        $foreign = Registration::factory()->create();
        $url = $this->groupsUrl($league, $tournament, '/assignments');

        $this->putJson($url, ['assignments' => [
            ['registration_id' => $one->id, 'group_id' => $b->id],
            ['registration_id' => $foreign->id, 'group_id' => $b->id],
        ]], $header)->assertStatus(422)->assertJsonStructure(['error' => ['assignments.1.registration_id']]);
        $this->assertSame($a->id, $one->fresh()->group_id);

        $otherGroup = Tournament::factory()->create()->groups()->firstOrFail();
        $this->putJson($url, ['assignments' => [['registration_id' => $one->id, 'group_id' => $otherGroup->id]]], $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['assignments.0.group_id']]);
        $this->assertSame($a->id, $one->fresh()->group_id);
    }

    public function test_rejects_playoff_groups_withdrawn_teams_and_repeated_registrations(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        [$one] = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 1);
        $withdrawn = Registration::factory()->status(RegistrationStatus::WITHDRAWN)->create(['tournament_id' => $tournament->id]);
        $playoff = $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);
        $url = $this->groupsUrl($league, $tournament, '/assignments');

        $this->putJson($url, ['assignments' => [['registration_id' => $one->id, 'group_id' => $playoff->id]]], $header)->assertStatus(422);
        $this->putJson($url, ['assignments' => [['registration_id' => $withdrawn->id, 'group_id' => null]]], $header)->assertStatus(422);
        $this->putJson($url, ['assignments' => [
            ['registration_id' => $one->id, 'group_id' => null],
            ['registration_id' => $one->id, 'group_id' => null],
        ]], $header)->assertStatus(422);
        $this->putJson($url, ['assignments' => []], $header)->assertStatus(422);
        $this->putJson($url, ['assignments' => [['registration_id' => $one->id]]], $header)->assertStatus(422);
    }

    public function test_random_assignment_balances_all_active_registrations(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->makeGroup($tournament, 'Grupo B');
        $this->makeGroup($tournament, 'Grupo C');
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 5);
        $this->teamsIn($tournament, null, 3);
        Registration::factory()->status(RegistrationStatus::WITHDRAWN)->create(['tournament_id' => $tournament->id]);

        $response = $this->postJson($this->groupsUrl($league, $tournament, '/random-assignment'), [], $header)->assertOk();

        $counts = collect($response->json('groups'))->pluck('teams_count');
        $this->assertSame(8, $counts->sum());
        $this->assertLessThanOrEqual(1, $counts->max() - $counts->min());
        $this->assertSame([], $response->json('unassigned'));
    }

    public function test_random_assignment_needs_at_least_two_registrations(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, null, 1);

        $this->postJson($this->groupsUrl($league, $tournament, '/random-assignment'), [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::GROUP_RANDOM_NEEDS_TEAMS);
    }
}
