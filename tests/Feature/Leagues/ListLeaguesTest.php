<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Message;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListLeaguesTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    public function test_lists_paginated_leagues_with_admin_and_status(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin(['email_verified_at' => null]);
        League::factory()->count(3)->create(['admin_user_id' => $admin->id]);

        $response = $this->getJson('/api/leagues?per_page=2', $this->authHeader($token))
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.admin.id', $admin->id)
            ->assertJsonPath('data.0.admin.status', 'pending');

        $this->assertSame(
            ['id', 'name', 'admin', 'created_at'],
            array_keys($response->json('data.0'))
        );
        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'status'],
            array_keys($response->json('data.0.admin'))
        );
    }

    public function test_searches_by_league_name_and_by_admin_data(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $ana = $this->createLeagueAdmin(['first_name' => 'Ana', 'last_name' => 'Zuñiga', 'email' => 'ana@mail.test']);
        $luis = $this->createLeagueAdmin(['first_name' => 'Luis', 'last_name' => 'Pérez', 'email' => 'luis@mail.test']);
        $byName = League::factory()->create(['admin_user_id' => $luis->id, 'name' => 'Liga Norteña']);
        $byAdmin = League::factory()->create(['admin_user_id' => $ana->id, 'name' => 'Liga Sur']);
        $other = League::factory()->create(['admin_user_id' => $luis->id, 'name' => 'Liga Centro']);

        $ids = fn (string $search) => collect(
            $this->getJson('/api/leagues?search='.urlencode($search), $this->authHeader($token))->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertSame([$byName->id], $ids('Norte'));
        $this->assertSame([$byAdmin->id], $ids('Zuñ'));
        $this->assertSame([$byAdmin->id], $ids('ana@mail'));
        $this->assertEqualsCanonicalizing([$byName->id, $other->id], $ids('Pérez'));
    }

    public function test_filters_by_admin_user_id_and_combines_with_search(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $ana = $this->createLeagueAdmin();
        $luis = $this->createLeagueAdmin();
        $a1 = League::factory()->create(['admin_user_id' => $ana->id, 'name' => 'Liga Alfa']);
        League::factory()->create(['admin_user_id' => $ana->id, 'name' => 'Liga Beta']);
        League::factory()->create(['admin_user_id' => $luis->id, 'name' => 'Liga Alfa Dos']);

        $this->getJson("/api/leagues?admin_user_id={$ana->id}", $this->authHeader($token))
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/leagues?admin_user_id={$ana->id}&search=Alfa", $this->authHeader($token))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a1->id);
    }

    public function test_ignores_empty_parameters(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        League::factory()->count(2)->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->getJson('/api/leagues?search=&admin_user_id=&sort=', $this->authHeader($token))
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_sorts_by_name_and_defaults_to_created_at_descending(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        $b = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'B', 'created_at' => now()->subDays(2)]);
        $a = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'A', 'created_at' => now()->subDay()]);
        $c = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'C', 'created_at' => now()->subDays(3)]);

        $names = fn (string $query) => collect(
            $this->getJson('/api/leagues'.$query, $this->authHeader($token))->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['A', 'B', 'C'], $names(''));
        $this->assertSame(['A', 'B', 'C'], $names('?sort=name'));
        $this->assertSame(['C', 'B', 'A'], $names('?sort=-name'));
        $this->assertSame(['C', 'B', 'A'], $names('?sort=created_at'));
    }

    public function test_invalid_sort_responds_400(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/leagues?sort=admin_user_id', $this->authHeader($token))
            ->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error' => Message::INVALID_QUERY_PARAMETER]);
    }

    public function test_invalid_admin_user_id_responds_400(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/leagues?admin_user_id=abc', $this->authHeader($token))->assertStatus(400);
    }

    public function test_excludes_deleted_leagues(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        $visible = League::factory()->create(['admin_user_id' => $admin->id]);
        League::factory()->create(['admin_user_id' => $admin->id])->delete();

        $this->getJson('/api/leagues', $this->authHeader($token))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_shows_a_league_and_404s_when_missing_or_deleted(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->getJson("/api/leagues/{$league->id}", $this->authHeader($token))
            ->assertOk()->assertJsonPath('id', $league->id)->assertJsonPath('admin.status', 'active');

        $this->getJson('/api/leagues/9999', $this->authHeader($token))
            ->assertStatus(404)
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);

        $league->delete();
        $this->getJson("/api/leagues/{$league->id}", $this->authHeader($token))->assertStatus(404);
    }
}
