<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_creates_a_pending_user_and_sends_the_invitation(): void
    {
        Notification::fake();
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'phone' => null,
            'roles' => [RoleEnum::LEAGUE_ADMIN->value],
        ], $this->authHeader($token))
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('is_active', true);

        $user = User::query()->where('email', 'ana@test.com')->firstOrFail();
        $this->assertNotNull($user->user_code);
        $this->assertSame(8, strlen($user->user_code));
        $this->assertNull($user->email_verified_at);
        $response->assertJsonPath('user_code', $user->user_code);

        Notification::assertSentTo($user, AccountInvitationNotification::class);
    }

    public function test_fails_when_roles_include_superadmin(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'roles' => [RoleEnum::SUPERADMIN->value],
        ], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['roles.0']]);
    }

    public function test_fails_when_roles_are_empty(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'roles' => [],
        ], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['roles']]);
    }

    public function test_fails_when_the_email_is_already_taken(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::LEAGUE_ADMIN);
        User::factory()->create(['email' => 'ana@test.com']);

        $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'roles' => [RoleEnum::LEAGUE_ADMIN->value],
        ], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['email']]);
    }

    public function test_fails_when_the_phone_is_already_taken(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::LEAGUE_ADMIN);
        User::factory()->create(['phone' => '5511112222']);

        $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'phone' => '5511112222',
            'roles' => [RoleEnum::LEAGUE_ADMIN->value],
        ], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['phone']]);
    }

    public function test_requires_phone_when_the_player_role_is_assigned(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::PLAYER);

        $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@test.com',
            'roles' => [RoleEnum::PLAYER->value],
        ], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['phone']]);
    }
}
