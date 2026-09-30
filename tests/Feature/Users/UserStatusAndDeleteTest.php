<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusAndDeleteTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_deactivates_a_user_and_revokes_its_tokens(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));
        $user->createToken('device');

        $this->patchJson("/api/users/{$user->id}/status", ['is_active' => false], $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('status', 'inactive');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reactivates_a_user(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->create(['is_active' => false]);
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        $this->patchJson("/api/users/{$user->id}/status", ['is_active' => true], $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('status', 'active');
    }

    public function test_cannot_deactivate_a_superadmin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $other = User::factory()->create();
        $other->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));

        $this->patchJson("/api/users/{$other->id}/status", ['is_active' => false], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::SUPERADMIN_PROTECTED]);
    }

    public function test_a_superadmin_cannot_deactivate_itself(): void
    {
        [$superadmin, $token] = $this->actingAsSuperadmin();

        $this->patchJson("/api/users/{$superadmin->id}/status", ['is_active' => false], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::CANNOT_MODIFY_SELF]);
    }

    public function test_deletes_a_user(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));
        $user->createToken('device');

        $this->deleteJson("/api/users/{$user->id}", [], $this->authHeader($token))
            ->assertNoContent();

        $this->assertSoftDeleted($user);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_cannot_delete_a_superadmin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $other = User::factory()->create();
        $other->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));

        $this->deleteJson("/api/users/{$other->id}", [], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::SUPERADMIN_PROTECTED]);
    }

    public function test_a_superadmin_cannot_delete_itself(): void
    {
        [$superadmin, $token] = $this->actingAsSuperadmin();

        $this->deleteJson("/api/users/{$superadmin->id}", [], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::CANNOT_MODIFY_SELF]);
    }

    public function test_a_deleted_user_is_no_longer_visible(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));
        $user->delete();

        $this->getJson("/api/users/{$user->id}", $this->authHeader($token))
            ->assertStatus(404)
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);
    }
}
