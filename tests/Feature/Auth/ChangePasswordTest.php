<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_changes_the_password_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $headers = $this->bearer($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ], $headers)
            ->assertOk()
            ->assertExactJson(['message' => 'Contraseña actualizada correctamente.']);

        $this->assertTrue(Hash::check('NuevaPassword123', $user->fresh()->password));
    }

    public function test_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $current = $this->bearer($user);
        $other = $this->bearer($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ], $current)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user', $current)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user', $other)
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_fails_when_the_current_password_is_incorrect(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $headers = $this->bearer($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'WrongPassword123',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ], $headers)
            ->assertStatus(401)
            ->assertExactJson(['code' => 401, 'error' => Message::CURRENT_PASSWORD_INVALID]);

        $this->assertTrue(Hash::check('CurrentPassword123', $user->fresh()->password));
    }

    public function test_requires_current_password_and_matching_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $headers = $this->bearer($user);

        $this->putJson('/api/user/password', [], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['current_password', 'password']]);
    }

    public function test_rejects_a_password_shorter_than_eight_characters(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $headers = $this->bearer($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['password']]);
    }

    public function test_rejects_a_new_password_equal_to_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword123')]);
        $headers = $this->bearer($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123',
            'password' => 'CurrentPassword123',
            'password_confirmation' => 'CurrentPassword123',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['password']]);
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123',
            'password' => 'NuevaPassword123',
            'password_confirmation' => 'NuevaPassword123',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }
}
