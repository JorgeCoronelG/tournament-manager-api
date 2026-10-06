<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyLeaguesTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_responds_401_without_token(): void
    {
        $this->getJson('/api/my-leagues')->assertStatus(401);
    }

    public function test_responds_403_to_other_roles(): void
    {
        $referee = User::factory()->create();
        $referee->roles()->attach($this->createRole(RoleEnum::REFEREE));

        $this->getJson('/api/my-leagues', $this->authHeader($this->tokenFor($referee)))->assertStatus(403);
    }

    public function test_league_admin_sees_only_their_leagues_with_id_and_name(): void
    {
        $me = $this->createLeagueAdmin();
        $other = $this->createLeagueAdmin();
        $b = League::factory()->create(['admin_user_id' => $me->id, 'name' => 'B Liga']);
        $a = League::factory()->create(['admin_user_id' => $me->id, 'name' => 'A Liga']);
        League::factory()->create(['admin_user_id' => $other->id]);

        $response = $this->getJson('/api/my-leagues?per_page=100', $this->authHeader($this->tokenFor($me)))
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data');

        $this->assertSame([['id' => $a->id, 'name' => 'A Liga'], ['id' => $b->id, 'name' => 'B Liga']], $response->json('data'));
    }

    public function test_league_admin_with_a_single_league(): void
    {
        $me = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $me->id]);
        League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->getJson('/api/my-leagues', $this->authHeader($this->tokenFor($me)))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_superadmin_sees_all_leagues_in_name_order_ignoring_sort(): void
    {
        [$superadmin, $token] = $this->actingAsSuperadmin();
        League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id, 'name' => 'Zeta']);
        League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id, 'name' => 'Alfa']);

        $names = collect($this->getJson('/api/my-leagues?sort=-name', $this->authHeader($token))->assertOk()->json('data'))
            ->pluck('name')->all();

        $this->assertSame(['Alfa', 'Zeta'], $names);
    }

    public function test_excludes_deleted_leagues(): void
    {
        $me = $this->createLeagueAdmin();
        $visible = League::factory()->create(['admin_user_id' => $me->id]);
        League::factory()->create(['admin_user_id' => $me->id])->delete();

        $this->getJson('/api/my-leagues', $this->authHeader($this->tokenFor($me)))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_searches_by_league_name_only(): void
    {
        $me = $this->createLeagueAdmin(['first_name' => 'Zacarias']);
        $norte = League::factory()->create(['admin_user_id' => $me->id, 'name' => 'Liga Norte']);
        League::factory()->create(['admin_user_id' => $me->id, 'name' => 'Liga Sur']);

        $header = $this->authHeader($this->tokenFor($me));

        $this->getJson('/api/my-leagues?search=Norte', $header)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $norte->id);
        $this->getJson('/api/my-leagues?search=Zacarias', $header)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/my-leagues?search=', $header)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_paginates(): void
    {
        $me = $this->createLeagueAdmin();
        League::factory()->count(5)->create(['admin_user_id' => $me->id]);

        $this->getJson('/api/my-leagues?per_page=2&page=3', $this->authHeader($this->tokenFor($me)))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.currentPage', 3);
    }
}
