<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivateAccountTest extends TestCase
{
    use RefreshDatabase;

    private function createPendingUser(string $email = 'jorge@test.com'): User
    {
        return User::factory()->unverified()->create([
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
        ]);
    }

    private function createCodeFor(User $user, string $code = '123456', ?Carbon $expiresAt = null): PasswordResetToken
    {
        return PasswordResetToken::query()->create([
            'email' => $user->email,
            'code' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt ?? now()->addHours(72),
        ]);
    }

    public function test_activates_the_account_with_a_valid_code(): void
    {
        $user = $this->createPendingUser();
        $this->createCodeFor($user);

        $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertOk()
            ->assertExactJson(['message' => 'Cuenta activada correctamente.']);

        $user->refresh();
        $this->assertTrue(Hash::check('NuevaPassword123', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_does_not_start_a_session_after_activation(): void
    {
        $user = $this->createPendingUser();
        $this->createCodeFor($user);

        $response = $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])->assertOk();

        $response->assertJsonMissing(['token']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_fails_when_the_code_is_incorrect_and_increments_attempts(): void
    {
        $user = $this->createPendingUser();
        $this->createCodeFor($user, '123456');

        $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '999999',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);

        $this->assertSame(1, PasswordResetToken::query()->find($user->email)->attempts);
    }

    public function test_fails_when_the_code_is_expired(): void
    {
        $user = $this->createPendingUser();
        $this->createCodeFor($user, '123456', now()->subMinute());

        $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);
    }

    public function test_fails_after_reaching_the_maximum_number_of_attempts(): void
    {
        $user = $this->createPendingUser();
        $this->createCodeFor($user, '123456')->update(['attempts' => 5]);

        $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(429)
            ->assertExactJson(['code' => 429, 'error' => Message::TOO_MANY_CODE_ATTEMPTS]);
    }

    public function test_fails_with_the_generic_error_when_the_account_is_already_active(): void
    {
        $user = User::factory()->create(['email' => 'jorge@test.com']);
        $this->createCodeFor($user);

        $this->postJson('/api/activate-account', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::CODE_INVALID_OR_EXPIRED]);
    }

    public function test_requires_code_email_and_matching_password_confirmation(): void
    {
        $this->postJson('/api/activate-account', [])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['email', 'code', 'password']]);
    }
}
