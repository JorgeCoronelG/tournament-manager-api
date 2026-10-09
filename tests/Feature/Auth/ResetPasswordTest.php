<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Leagues\InteractsWithLeagues;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    private function createCodeFor(User $user, string $code = '123456', ?Carbon $expiresAt = null): PasswordResetToken
    {
        return PasswordResetToken::query()->create([
            'email' => $user->email,
            'code' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt ?? now()->addMinutes(15),
        ]);
    }

    public function test_resets_the_password_with_a_valid_code(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertOk()
            ->assertExactJson(['message' => 'Contraseña actualizada correctamente.']);

        $this->assertTrue(Hash::check('NuevaPassword123', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_revokes_all_existing_tokens_after_a_successful_reset(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user);
        $user->createToken('device-1');
        $user->createToken('device-2');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_fails_when_there_is_no_pending_code(): void
    {
        $user = $this->createLeagueAdmin();

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);
    }

    public function test_fails_when_the_code_is_expired(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user, '123456', now()->subMinute());

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);
    }

    public function test_fails_and_increments_attempts_when_the_code_does_not_match(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user, '123456');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '999999',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);

        $this->assertSame(1, PasswordResetToken::query()->find($user->email)->attempts);
    }

    public function test_fails_after_reaching_the_maximum_number_of_attempts(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user, '123456')->update(['attempts' => 5]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(429)
            ->assertExactJson(['code' => 429, 'error' => Message::TOO_MANY_CODE_ATTEMPTS]);
    }

    /**
     * @return array<string, array{0: list<RoleEnum>, 1: bool, 2: bool}> roles, is_active, verificado
     */
    public static function ineligibleUsers(): array
    {
        return [
            'sin rol de administrador' => [[RoleEnum::PLAYER], true, true],
            'sin roles' => [[], true, true],
            'desactivado' => [[RoleEnum::LEAGUE_ADMIN], false, true],
            'pendiente' => [[RoleEnum::LEAGUE_ADMIN], true, false],
        ];
    }

    /**
     * @dataProvider ineligibleUsers
     *
     * @param  list<RoleEnum>  $roles
     */
    public function test_a_valid_code_is_rejected_when_the_user_is_no_longer_eligible(array $roles, bool $isActive, bool $verified): void
    {
        $user = User::factory()->create([
            'is_active' => $isActive,
            'email_verified_at' => $verified ? now() : null,
        ]);
        foreach ($roles as $role) {
            $user->roles()->attach($this->createRole($role));
        }
        $oldPassword = $user->password;
        $user->createToken('device');
        $this->createCodeFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);

        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->assertSame($verified, $user->fresh()->email_verified_at !== null);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_resets_the_password_of_a_superadmin(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));
        $this->createCodeFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])->assertOk();
    }

    public function test_requires_code_email_and_matching_password_confirmation(): void
    {
        $this->postJson('/api/reset-password', [])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['email', 'code', 'password']]);
    }

    public function test_requires_the_code_to_have_exactly_six_digits(): void
    {
        $user = $this->createLeagueAdmin();

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '123',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['code']]);
    }

    public function test_is_rate_limited_per_email_and_ip(): void
    {
        $user = $this->createLeagueAdmin();
        $this->createCodeFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'email' => $user->email,
                'code' => '000000',
                'password' => 'NuevaPassword123',
                'password_confirmation' => 'NuevaPassword123',
            ])->assertStatus(422);
        }

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
