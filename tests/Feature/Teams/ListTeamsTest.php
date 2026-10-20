<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\TeamStatus;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListTeamsTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_lists_paginated_with_envelope_and_only_the_league_teams(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Team::factory()->count(3)->create(['league_id' => $league->id]);
        Team::factory()->create();
        Team::factory()->create(['league_id' => $league->id])->delete();

        $this->getJson("/api/leagues/{$league->id}/teams?per_page=2", $header)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_default_sort_is_name_ascending_and_sort_param_is_honored(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $b = Team::factory()->create(['league_id' => $league->id, 'name' => 'B', 'created_at' => now()->subDay()]);
        $a = Team::factory()->create(['league_id' => $league->id, 'name' => 'A', 'created_at' => now()]);
        $url = "/api/leagues/{$league->id}/teams";

        $this->assertSame([$a->id, $b->id], $this->getJson($url, $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("{$url}?sort=-name", $header)->json('data.*.id'));
        $this->assertSame([$a->id, $b->id], $this->getJson("{$url}?sort=-created_at", $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("{$url}?sort=created_at", $header)->json('data.*.id'));
        $this->getJson("{$url}?sort=status", $header)->assertStatus(400);
    }

    public function test_search_matches_the_team_name_or_the_manager_name_email_or_code(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(['first_name' => 'Ana', 'last_name' => 'Soto', 'email' => 'ana.soto@mail.test', 'user_code' => 'DEL12345']);
        $byManager = Team::factory()->create(['league_id' => $league->id, 'name' => 'Tigres', 'manager_user_id' => $manager->id]);
        $byName = Team::factory()->create(['league_id' => $league->id, 'name' => 'Soto FC']);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Leones']);
        $url = "/api/leagues/{$league->id}/teams";

        $this->assertSame([$byName->id, $byManager->id], $this->getJson("{$url}?search=soto", $header)->json('data.*.id'));
        $this->assertSame([$byManager->id], $this->getJson("{$url}?search=Ana Soto", $header)->json('data.*.id'));
        $this->assertSame([$byManager->id], $this->getJson("{$url}?search=ana.soto@mail", $header)->json('data.*.id'));
        $this->assertSame([$byManager->id], $this->getJson("{$url}?search=DEL123", $header)->json('data.*.id'));
        $this->assertCount(3, $this->getJson("{$url}?search=", $header)->json('data'));
    }

    public function test_filters_by_manager_and_lists_the_managers_of_the_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $ana = $this->createManagerUser(['first_name' => 'Ana', 'last_name' => 'Soto']);
        $beto = $this->createManagerUser(['first_name' => 'Beto', 'last_name' => 'Ruiz']);
        $other = $this->createManagerUser(['first_name' => 'Carlos', 'last_name' => 'Otro']);
        $first = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $ana->id]);
        Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $ana->id]);
        $second = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $beto->id]);
        Team::factory()->create(['league_id' => $league->id]);
        Team::factory()->create(['manager_user_id' => $other->id]);
        $url = "/api/leagues/{$league->id}/teams";

        $this->assertSame([$second->id], $this->getJson("{$url}?manager_user_id={$beto->id}", $header)->json('data.*.id'));
        $this->assertCount(2, $this->getJson("{$url}?manager_user_id={$ana->id}", $header)->json('data'));
        $this->assertCount(4, $this->getJson("{$url}?manager_user_id=", $header)->json('data'));
        $this->getJson("{$url}?manager_user_id=abc", $header)->assertStatus(400);

        $response = $this->getJson("{$url}/managers", $header)->assertOk();
        $this->assertSame([$ana->id, $beto->id], $response->json('*.id'));
        $this->assertSame(['id', 'first_name', 'last_name'], array_keys($response->json('0')));
        $this->assertNotNull($first);
    }

    public function test_filters_by_status_and_has_manager(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $withManager = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);
        $banned = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/teams";

        $this->assertSame([$banned->id], $this->getJson("{$url}?status=banned", $header)->json('data.*.id'));
        $this->assertSame([$withManager->id], $this->getJson("{$url}?status=active&has_manager=true", $header)->json('data.*.id'));
        $this->assertSame([$withManager->id], $this->getJson("{$url}?has_manager=1", $header)->json('data.*.id'));
        $this->assertSame([$banned->id], $this->getJson("{$url}?has_manager=false", $header)->json('data.*.id'));
        $this->assertCount(2, $this->getJson("{$url}?has_manager=", $header)->json('data'));
        $this->getJson("{$url}?status=otro", $header)->assertStatus(400);
        $this->getJson("{$url}?has_manager=quizas", $header)->assertStatus(400);
    }

    public function test_each_item_carries_the_manager_or_null(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(pending: true);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'A', 'manager_user_id' => $manager->id]);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'B']);

        $data = $this->getJson("/api/leagues/{$league->id}/teams", $header)->assertOk()->json('data');

        $this->assertSame('pending', $data[0]['manager']['account_status']);
        $this->assertNull($data[1]['manager']);
    }
}
