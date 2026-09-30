<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResendInvitationTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_resends_the_invitation_for_a_pending_user_and_invalidates_the_previous_code(): void
    {
        Notification::fake();
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->unverified()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        PasswordResetToken::query()->create([
            'email' => $user->email,
            'code' => Hash::make('111111'),
            'attempts' => 0,
            'expires_at' => now()->addHours(72),
        ]);

        $this->postJson("/api/users/{$user->id}/resend-invitation", [], $this->authHeader($token))
            ->assertOk();

        $newCode = PasswordResetToken::query()->find($user->email);
        $this->assertFalse(Hash::check('111111', $newCode->code));
        Notification::assertSentTo($user, AccountInvitationNotification::class);
    }

    public function test_fails_when_the_user_is_already_active(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        $this->postJson("/api/users/{$user->id}/resend-invitation", [], $this->authHeader($token))
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::INVITATION_ALREADY_ACTIVE]);
    }

    public function test_is_rate_limited_to_once_per_minute(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $user = User::factory()->unverified()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        $this->postJson("/api/users/{$user->id}/resend-invitation", [], $this->authHeader($token))
            ->assertOk();

        $this->postJson("/api/users/{$user->id}/resend-invitation", [], $this->authHeader($token))
            ->assertStatus(429);
    }
}
