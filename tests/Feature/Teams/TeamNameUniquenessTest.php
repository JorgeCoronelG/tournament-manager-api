<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Message;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamNameUniquenessTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_same_name_and_same_manager_is_rejected_ignoring_case_and_spaces(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Deportivo Hidalgo', 'manager_user_id' => $manager->id]);
        $url = "/api/leagues/{$league->id}/teams";

        $this->postJson($url, ['name' => 'Deportivo Hidalgo', 'manager_user_code' => $manager->user_code], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.name.0', Message::TEAM_NAME_TAKEN_BY_MANAGER);
        $this->postJson($url, ['name' => '  deportivo   HIDALGO ', 'manager_user_code' => $manager->user_code], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.name.0', Message::TEAM_NAME_TAKEN_BY_MANAGER);
    }

    public function test_same_name_with_different_managers_is_allowed(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $first = $this->createManagerUser();
        $second = $this->createManagerUser();
        $url = "/api/leagues/{$league->id}/teams";

        $this->postJson($url, ['name' => 'Hidalgo', 'manager_user_code' => $first->user_code], $header)->assertCreated();
        $this->postJson($url, ['name' => 'Hidalgo', 'manager_user_code' => $second->user_code], $header)->assertCreated();

        $this->assertSame(2, Team::query()->where('league_id', $league->id)->count());
    }

    public function test_same_name_with_a_brand_new_manager_is_allowed(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Hidalgo', 'manager_user_id' => $this->createManagerUser()->id]);

        $this->postJson(
            "/api/leagues/{$league->id}/teams",
            ['name' => 'Hidalgo', 'manager' => $this->managerPayload()],
            $header
        )->assertCreated();
    }

    public function test_two_teams_without_manager_cannot_share_a_name_but_one_with_and_one_without_can(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/teams";

        $this->postJson($url, ['name' => 'Hidalgo'], $header)->assertCreated();
        $this->postJson($url, ['name' => 'hidalgo'], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.name.0', Message::TEAM_NAME_TAKEN_WITHOUT_MANAGER);
        $this->postJson($url, ['name' => 'Hidalgo', 'manager_user_code' => $this->createManagerUser()->user_code], $header)
            ->assertCreated();
    }

    public function test_assigning_or_changing_the_manager_checks_the_name_against_the_new_manager(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $busy = $this->createManagerUser();
        $free = $this->createManagerUser();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Hidalgo', 'manager_user_id' => $busy->id]);
        $team = Team::factory()->create(['league_id' => $league->id, 'name' => 'hidalgo']);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}/manager";

        $this->putJson($url, ['manager_user_code' => $busy->user_code], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', Message::TEAM_NAME_TAKEN_BY_MANAGER);
        $this->assertNull($team->fresh()->manager_user_id);

        $this->putJson($url, ['manager_user_code' => $free->user_code], $header)->assertOk();
    }

    public function test_removing_the_manager_checks_for_another_team_without_manager_with_that_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'name' => 'Hidalgo', 'manager_user_id' => $manager->id]);
        $other = Team::factory()->create(['league_id' => $league->id, 'name' => 'Hidalgo']);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}/manager";

        $this->deleteJson($url, [], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', Message::TEAM_NAME_TAKEN_WITHOUT_MANAGER);
        $this->assertSame($manager->id, $team->fresh()->manager_user_id);

        $other->delete();
        $this->deleteJson($url, [], $header)->assertOk()->assertJsonPath('manager', null);
    }

    public function test_renaming_checks_the_current_manager_and_saving_unchanged_is_fine(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $other = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $manager->id]);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Leones', 'manager_user_id' => $manager->id]);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Pumas', 'manager_user_id' => $other->id]);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}";

        $this->putJson($url, ['name' => 'Tigres'], $header)->assertOk();
        $this->putJson($url, ['name' => ' LEONES '], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.name.0', Message::TEAM_NAME_TAKEN_BY_MANAGER);
        $this->putJson($url, ['name' => 'Pumas'], $header)->assertOk();
    }

    public function test_name_of_a_deleted_team_can_be_reused_and_other_leagues_do_not_conflict(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Hidalgo', 'manager_user_id' => $manager->id])->delete();
        Team::factory()->create(['league_id' => League::factory()->create()->id, 'name' => 'Leones', 'manager_user_id' => $manager->id]);
        $url = "/api/leagues/{$league->id}/teams";

        $this->postJson($url, ['name' => 'Hidalgo', 'manager_user_code' => $manager->user_code], $header)->assertCreated();
        $this->postJson($url, ['name' => 'Leones', 'manager_user_code' => $manager->user_code], $header)->assertCreated();
    }

    public function test_listing_sorted_by_a_repeated_name_is_stable_across_pages(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $ids = Team::factory()->count(5)->create(['league_id' => $league->id, 'name' => 'Hidalgo'])->pluck('id')->all();
        $url = "/api/leagues/{$league->id}/teams?sort=name&per_page=2";

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->getJson("{$url}&page={$page}", $header)->json('data.*.id'));
        }

        $this->assertSame($ids, $seen);
    }
}
