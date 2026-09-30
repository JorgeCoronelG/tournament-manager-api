<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_user_and_token_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'jorge@test.com']);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'roles'], 'token', 'token_type']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_returns_the_roles_of_the_user(): void
    {
        $user = User::factory()->create(['email' => 'jorge@test.com']);
        $role = Role::query()->forceCreate([
            'id' => RoleEnum::SUPERADMIN->value,
            'name' => RoleEnum::SUPERADMIN->label(),
        ]);
        $user->roles()->attach($role);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.roles', [
                ['id' => 1, 'code' => 'superadmin', 'name' => 'Super Administrador'],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'jorge@test.com']);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_unknown_email(): void
    {
        $this->postJson('/api/login', [
            'email' => 'no-existe@test.com',
            'password' => 'password',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['code', 'error' => ['email', 'password']]);
    }

    public function test_login_requires_a_valid_email_format(): void
    {
        $this->postJson('/api/login', [
            'email' => 'no-es-un-correo',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['email']]);
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'jorge@test.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'jorge@test.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertExactJson(['code' => 429, 'error' => Message::THROTTLE_REQUESTS_EXCEPTION])
            ->assertHeader('Retry-After');
    }
}
