<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Message;
use App\Core\Enum\TeamStatus;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveTeamTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_creates_an_active_team_without_manager_in_the_route_league_trimming_the_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $other = League::factory()->create();

        $response = $this->postJson(
            "/api/leagues/{$league->id}/teams",
            ['name' => '  Tigres  ', 'league_id' => $other->id, 'status' => 'banned', 'manager_user_id' => 1, 'logo_path' => 'x.webp'],
            $header
        )->assertCreated()
            ->assertJsonPath('league_id', $league->id)
            ->assertJsonPath('name', 'Tigres')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('logo_url', null)
            ->assertJsonPath('manager', null);

        $this->assertSame(
            ['id', 'league_id', 'name', 'status', 'logo_url', 'manager', 'created_at', 'updated_at'],
            array_keys($response->json())
        );
        $this->assertDatabaseHas('teams', ['id' => $response->json('id'), 'manager_user_id' => null, 'logo_path' => null]);
    }

    public function test_shows_a_team_with_its_manager(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(['first_name' => 'Ana', 'last_name' => 'Soto']);
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}", $header)
            ->assertOk()
            ->assertJsonPath('manager.id', $manager->id)
            ->assertJsonPath('manager.user_code', $manager->user_code)
            ->assertJsonPath('manager.first_name', 'Ana')
            ->assertJsonPath('manager.last_name', 'Soto')
            ->assertJsonPath('manager.account_status', 'active');

        $this->assertSame(['id', 'user_code', 'first_name', 'last_name', 'email', 'phone', 'photo_url', 'account_status'], array_keys($response->json('manager')));
    }

    public function test_update_changes_only_the_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $team = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->putJson(
            "/api/leagues/{$league->id}/teams/{$team->id}",
            ['name' => 'Nuevo', 'status' => 'active', 'manager_user_code' => 'XXXX', 'manager' => $this->managerPayload()],
            $header
        )->assertOk()->assertJsonPath('name', 'Nuevo')->assertJsonPath('status', 'banned')->assertJsonPath('manager.id', $manager->id);
    }

    public function test_name_must_be_present_and_at_most_150_characters(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/teams";

        $this->postJson($url, ['name' => ''], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => '   '], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => str_repeat('a', 151)], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => str_repeat('a', 150)], $header)->assertCreated();
    }

    public function test_duplicate_name_in_the_same_league_is_rejected_ignoring_case_and_spaces(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres']);

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => '  tIGRES '], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.name.0', Message::TEAM_NAME_TAKEN_WITHOUT_MANAGER);
    }

    public function test_update_ignores_the_team_itself_but_not_the_others(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres']);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Leones']);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}";

        $this->putJson($url, ['name' => 'TIGRES'], $header)->assertOk();
        $this->putJson($url, ['name' => 'leones'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
    }

    public function test_same_name_is_allowed_in_another_league_or_as_a_deleted_team(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Team::factory()->create(['name' => 'Tigres']);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Leones'])->delete();

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres'], $header)->assertCreated();
        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Leones'], $header)->assertCreated();
    }

    public function test_status_patch_switches_between_active_and_banned(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}/status";

        $this->patchJson($url, ['status' => 'banned'], $header)->assertOk()->assertJsonPath('status', 'banned');
        $this->patchJson($url, ['status' => 'active'], $header)->assertOk()->assertJsonPath('status', 'active');
        $this->patchJson($url, ['status' => 'bogus'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
        $this->patchJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
    }

    public function test_delete_is_soft_and_keeps_the_logo_file(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id, 'logo_path' => 'teams/logos/keep.webp']);

        $this->deleteJson("/api/leagues/{$league->id}/teams/{$team->id}", [], $header)->assertNoContent();

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}", $header)->assertNotFound();
    }
}
