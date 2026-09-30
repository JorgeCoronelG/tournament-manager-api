<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UpdateUserTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_updates_a_user(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::LEAGUE_ADMIN);
        $this->createRole(RoleEnum::REFEREE);
        $user = User::factory()->create();
        $user->roles()->attach(RoleEnum::LEAGUE_ADMIN->value);

        $this->putJson("/api/users/{$user->id}", [
            'first_name' => 'Nuevo',
            'last_name' => 'Nombre',
            'email' => $user->email,
            'phone' => null,
            'roles' => [RoleEnum::REFEREE->value],
            'is_active' => true,
        ], $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('first_name', 'Nuevo')
            ->assertJsonPath('roles.0.code', 'referee');

        $this->assertSame('Nuevo', $user->fresh()->first_name);
    }

    public function test_cannot_update_a_superadmin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $other = User::factory()->create();
        $other->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));

        $this->putJson("/api/users/{$other->id}", [
            'first_name' => 'Nuevo',
            'last_name' => 'Nombre',
            'email' => $other->email,
            'roles' => [$this->createRole(RoleEnum::LEAGUE_ADMIN)->id],
            'is_active' => true,
        ], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::SUPERADMIN_PROTECTED]);
    }

    public function test_resends_the_invitation_when_the_email_of_a_pending_user_changes(): void
    {
        Notification::fake();
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);
        $user = User::factory()->unverified()->create();
        $user->roles()->attach($role);

        $this->putJson("/api/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'nuevo@test.com',
            'roles' => [$role->id],
            'is_active' => true,
        ], $this->authHeader($token))->assertOk();

        $user->refresh();
        $this->assertSame('nuevo@test.com', $user->email);
        Notification::assertSentTo($user, AccountInvitationNotification::class);
        Notification::assertNotSentTo($user, PasswordResetCodeNotification::class);
    }

    public function test_revokes_tokens_when_deactivated_through_the_update_endpoint(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->createToken('device');

        $this->putJson("/api/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => [$role->id],
            'is_active' => false,
        ], $this->authHeader($token))->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse($user->fresh()->is_active);
    }
}
