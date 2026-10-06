<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LeagueAccessMiddlewareTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'permission:1,2', 'league.access'])
            ->get('/api/leagues/{league}/probe', fn (League $league) => response()->json(['id' => $league->id]));
    }

    public function test_responds_401_without_token(): void
    {
        $league = League::factory()->create();

        $this->getJson("/api/leagues/{$league->id}/probe")->assertStatus(401);
        $this->getJson('/api/leagues/9999/probe')->assertStatus(401);
    }

    public function test_superadmin_accesses_any_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->getJson("/api/leagues/{$league->id}/probe", $this->authHeader($token))
            ->assertOk()->assertJsonPath('id', $league->id);
    }

    public function test_league_admin_accesses_their_league_but_not_anothers(): void
    {
        $me = $this->createLeagueAdmin();
        $mine = League::factory()->create(['admin_user_id' => $me->id]);
        $theirs = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);
        $header = $this->authHeader($me->createToken('t')->plainTextToken);

        $this->getJson("/api/leagues/{$mine->id}/probe", $header)->assertOk();
        $this->getJson("/api/leagues/{$theirs->id}/probe", $header)
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::AUTHORIZATION_EXCEPTION]);
    }

    public function test_missing_or_deleted_league_responds_404(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $deleted = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);
        $deleted->delete();

        $this->getJson('/api/leagues/9999/probe', $this->authHeader($token))
            ->assertStatus(404)
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);
        $this->getJson("/api/leagues/{$deleted->id}/probe", $this->authHeader($token))->assertStatus(404);
    }

    public function test_other_roles_get_403_even_for_a_missing_league(): void
    {
        $referee = User::factory()->create();
        $referee->roles()->attach($this->createRole(RoleEnum::REFEREE));

        $this->getJson('/api/leagues/9999/probe', $this->authHeader($referee->createToken('t')->plainTextToken))
            ->assertStatus(403);
    }
}
