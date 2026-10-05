<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueAdminGuardsTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function bodyFor(User $user, array $roles): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $roles,
            'is_active' => true,
        ];
    }

    public function test_cannot_remove_league_admin_role_from_a_league_admin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->count(2)->create(['admin_user_id' => $admin->id]);
        $this->createRole(RoleEnum::REFEREE);

        $response = $this->putJson("/api/users/{$admin->id}", $this->bodyFor($admin, [RoleEnum::REFEREE->value]), $this->authHeader($token))
            ->assertStatus(422);

        $this->assertStringContainsString('2 ligas', $response->json('error'));
        $this->assertTrue($admin->fresh()->roles->contains('id', RoleEnum::LEAGUE_ADMIN->value));
    }

    public function test_singular_message_with_one_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id]);
        $this->createRole(RoleEnum::REFEREE);

        $response = $this->putJson("/api/users/{$admin->id}", $this->bodyFor($admin, [RoleEnum::REFEREE->value]), $this->authHeader($token))
            ->assertStatus(422);

        $this->assertStringContainsString('una liga', $response->json('error'));
    }

    public function test_can_change_other_roles_while_keeping_league_admin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id]);
        $this->createRole(RoleEnum::REFEREE);

        $this->putJson("/api/users/{$admin->id}", $this->bodyFor($admin, [RoleEnum::LEAGUE_ADMIN->value, RoleEnum::REFEREE->value]), $this->authHeader($token))
            ->assertOk();
    }

    public function test_can_remove_role_when_only_deleted_leagues_remain(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id])->delete();
        $this->createRole(RoleEnum::REFEREE);

        $this->putJson("/api/users/{$admin->id}", $this->bodyFor($admin, [RoleEnum::REFEREE->value]), $this->authHeader($token))
            ->assertOk();
    }

    public function test_cannot_delete_a_league_admin_with_leagues(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id]);

        $this->deleteJson("/api/users/{$admin->id}", [], $this->authHeader($token))->assertStatus(422);

        $this->assertNotSoftDeleted($admin);
    }

    public function test_can_deactivate_a_league_admin_with_leagues(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id]);

        $this->patchJson("/api/users/{$admin->id}/status", ['is_active' => false], $this->authHeader($token))->assertOk();

        $this->getJson("/api/leagues/{$league->id}", $this->authHeader($token))
            ->assertOk()->assertJsonPath('admin.status', 'inactive');
    }

    public function test_can_delete_a_league_admin_after_deleting_its_leagues(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id])->delete();

        $this->deleteJson("/api/users/{$admin->id}", [], $this->authHeader($token))->assertNoContent();
    }
}
