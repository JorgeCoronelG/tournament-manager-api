<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Registration;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupCrudTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    public function test_lists_groups_with_counts_and_unassigned_teams(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = $tournament->groups()->firstOrFail();
        $second = $this->makeGroup($tournament, 'Grupo B');
        $this->teamsIn($tournament, $first, 2);
        $this->teamsIn($tournament, $second, 1);
        $this->teamsIn($tournament, null, 1);

        $this->getJson($this->groupsUrl($league, $tournament), $header)
            ->assertOk()
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.name', 'Fase regular')
            ->assertJsonPath('groups.0.type', 'regular_stage')
            ->assertJsonPath('groups.0.teams_count', 2)
            ->assertJsonPath('groups.1.teams_count', 1)
            ->assertJsonCount(1, 'unassigned')
            ->assertJsonStructure(['unassigned' => [['id', 'team' => ['id', 'name', 'logo_url', 'manager']]]])
            ->assertJsonPath('finals_aligned', true);
    }

    public function test_shows_a_group_with_its_teams(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $teams = $this->teamsIn($tournament, $group, 2);

        $this->getJson($this->groupsUrl($league, $tournament, "/{$group->id}"), $header)
            ->assertOk()
            ->assertJsonPath('id', $group->id)
            ->assertJsonPath('teams_count', 2)
            ->assertJsonPath('teams.0.id', $teams[0]->id)
            ->assertJsonStructure(['teams' => [['id', 'team' => ['id', 'name']]]]);
    }

    public function test_creates_a_regular_group_and_existing_teams_stay_in_the_first_one(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = $tournament->groups()->firstOrFail();
        $this->teamsIn($tournament, $first, 2);

        $this->postJson($this->groupsUrl($league, $tournament), ['name' => 'Grupo B'], $header)
            ->assertCreated()->assertJsonPath('name', 'Grupo B')->assertJsonPath('type', 'regular_stage');

        $this->assertSame(2, Registration::query()->where('group_id', $first->id)->count());
    }

    public function test_name_is_required_and_unique_ignoring_case_accents_and_spaces(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->makeGroup($tournament, 'Grupo Águilas');
        $other = $this->makeGroup($tournament, 'Grupo B');

        $this->postJson($this->groupsUrl($league, $tournament), [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($this->groupsUrl($league, $tournament), ['name' => str_repeat('a', 101)], $header)->assertStatus(422);
        $this->postJson($this->groupsUrl($league, $tournament), ['name' => '  grupo   aguilas '], $header)
            ->assertStatus(422)->assertJsonPath('error.name.0', Message::GROUP_NAME_TAKEN);
        $this->patchJson($this->groupsUrl($league, $tournament, "/{$other->id}"), ['name' => 'GRUPO ÁGUILAS'], $header)->assertStatus(422);

        // Conservar su propio nombre o ponerse el de un grupo de otro torneo sí se puede.
        $this->patchJson($this->groupsUrl($league, $tournament, "/{$other->id}"), ['name' => 'grupo b'], $header)->assertOk();
    }

    public function test_renames_a_group(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();

        $this->patchJson($this->groupsUrl($league, $tournament, "/{$group->id}"), ['name' => 'Zona Norte'], $header)
            ->assertOk()->assertJsonPath('name', 'Zona Norte');
        $this->assertSame('Zona Norte', $group->fresh()->name);
    }

    public function test_deletes_an_empty_group_and_the_rest_get_the_unassigned_teams(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = $tournament->groups()->firstOrFail();
        $second = $this->makeGroup($tournament, 'Grupo B');
        $unassigned = $this->teamsIn($tournament, null, 2);
        $this->teamsIn($tournament, $first, 1);

        $this->deleteJson($this->groupsUrl($league, $tournament, "/{$second->id}"), [], $header)->assertNoContent();

        $this->assertSoftDeleted('tournament_groups', ['id' => $second->id]);
        $this->assertSame($first->id, $unassigned[0]->fresh()->group_id);
        $this->assertSame($first->id, $unassigned[1]->fresh()->group_id);
    }

    public function test_cannot_delete_the_last_regular_group(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);

        $this->deleteJson($this->groupsUrl($league, $tournament, "/{$group->id}"), [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::GROUP_DELETE_LAST_REGULAR);
    }

    public function test_cannot_delete_a_group_with_teams_matches_or_used_by_a_playoff(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->makeGroup($tournament, 'Grupo B');
        $withTeams = $this->makeGroup($tournament, 'Con equipos');
        $withMatches = $this->makeGroup($tournament, 'Con partidos');
        $used = $this->makeGroup($tournament, 'Fuente');
        $this->teamsIn($tournament, $withTeams, 1);
        [$home, $away] = $this->teamsIn($tournament, null, 2);
        DB::table('matches')->insert([
            'tournament_id' => $tournament->id, 'group_id' => $withMatches->id,
            'home_registration_id' => $home->id, 'away_registration_id' => $away->id,
            'phase' => 'regular_stage', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $playoff = $this->makeGroup($tournament, 'Liguilla', TournamentGroupType::PLAYOFF);
        DB::table('playoff_sources')->insert([
            'playoff_group_id' => $playoff->id, 'source_group_id' => $used->id, 'position_start' => 1, 'position_end' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$withTeams, Message::GROUP_DELETE_HAS_TEAMS], [$withMatches, Message::GROUP_DELETE_HAS_MATCHES], [$used, Message::GROUP_DELETE_USED_BY_PLAYOFF]] as [$group, $message]) {
            $this->deleteJson($this->groupsUrl($league, $tournament, "/{$group->id}"), [], $header)
                ->assertStatus(422)->assertJsonPath('error', $message);
            $this->assertNotSoftDeleted('tournament_groups', ['id' => $group->id]);
        }
    }

    public function test_rejects_every_change_when_the_tournament_is_not_in_planning(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $this->makeGroup($tournament, 'Grupo B');
        $registration = $this->teamsIn($tournament, $group, 2)[0];

        foreach ([[TournamentStatus::IN_PROGRESS, Message::GROUPS_TOURNAMENT_IN_PROGRESS], [TournamentStatus::PLAYOFFS, Message::GROUPS_TOURNAMENT_PLAYOFFS], [TournamentStatus::FINISHED, Message::GROUPS_TOURNAMENT_FINISHED]] as [$status, $message]) {
            $tournament->forceFill(['status' => $status])->save();

            $this->postJson($this->groupsUrl($league, $tournament), ['name' => 'Nuevo'], $header)->assertStatus(422)->assertJsonPath('error', $message);
            $this->patchJson($this->groupsUrl($league, $tournament, "/{$group->id}"), ['name' => 'Otro'], $header)->assertStatus(422)->assertJsonPath('error', $message);
            $this->deleteJson($this->groupsUrl($league, $tournament, "/{$group->id}"), [], $header)->assertStatus(422)->assertJsonPath('error', $message);
            $this->putJson($this->groupsUrl($league, $tournament, '/assignments'), ['assignments' => [['registration_id' => $registration->id, 'group_id' => null]]], $header)
                ->assertStatus(422)->assertJsonPath('error', $message);
            $this->postJson($this->groupsUrl($league, $tournament, '/random-assignment'), [], $header)->assertStatus(422)->assertJsonPath('error', $message);
        }

        $this->assertSame($group->id, $registration->fresh()->group_id);
        $this->assertSame(TournamentGroup::query()->where('tournament_id', $tournament->id)->count(), 2);
    }

    public function test_removing_a_registration_leaves_it_without_group(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registration = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 1)[0];

        $this->deleteJson($this->registrationsUrl($league, $tournament, "/{$registration->id}"), [], $header)->assertNoContent();

        $this->assertNull(Registration::withTrashed()->find($registration->id)?->group_id);
    }
}
