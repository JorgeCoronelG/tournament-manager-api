<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefereeAuthorizationTest extends TestCase
{
    use InteractsWithReferees, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'lookup' => ['GET', '/lookup?user_code=ABC'],
            'attach' => ['POST', '/attach'],
            'update' => ['PUT', '/1'],
            'status' => ['PATCH', '/1/status'],
            'destroy' => ['DELETE', '/1'],
            'resend' => ['POST', '/1/resend-invitation'],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        $league = League::factory()->create();

        $this->json($method, "/api/leagues/{$league->id}/referees{$suffix}")->assertStatus(401);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_403_for_other_roles(string $method, string $suffix): void
    {
        $league = League::factory()->create();

        foreach ([RoleEnum::REFEREE, RoleEnum::MANAGER, RoleEnum::PLAYER] as $role) {
            $this->json($method, "/api/leagues/{$league->id}/referees{$suffix}", [], $this->headerForRole($role))
                ->assertStatus(403);
        }
    }

    /**
     * @dataProvider endpoints
     */
    public function test_league_admin_cannot_operate_on_a_foreign_league(string $method, string $suffix): void
    {
        [, $header] = $this->leagueWithAdminHeader();
        $foreign = League::factory()->create();

        $this->json($method, "/api/leagues/{$foreign->id}/referees{$suffix}", [], $header)->assertStatus(403);
    }

    public function test_responds_404_for_a_missing_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/leagues/99999/referees', $this->authHeader($token))->assertStatus(404);
    }

    public function test_superadmin_operates_on_any_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $referee = $this->createRefereeUser();
        $header = $this->authHeader($token);

        $this->getJson("/api/leagues/{$league->id}/referees", $header)->assertOk();
        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $referee->user_code], $header)->assertOk();
    }

    public function test_league_admin_operates_on_their_own_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->getJson("/api/leagues/{$league->id}/referees", $header)->assertOk();
    }
}
