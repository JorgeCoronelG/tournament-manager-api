<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithLogin, RefreshDatabase;

    public function test_login_returns_user_and_token_with_valid_credentials(): void
    {
        $user = $this->createUserWithRoles([RoleEnum::LEAGUE_ADMIN]);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'password',
            'client' => 'web',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'roles'], 'token', 'token_type']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame('web', PersonalAccessToken::query()->firstOrFail()->name);
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
            'client' => 'web',
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
            'client' => 'web',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_403_when_the_account_is_inactive(): void
    {
        User::factory()->create(['email' => 'jorge@test.com', 'is_active' => false]);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'password',
            'client' => 'web',
        ])
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::ACCOUNT_INACTIVE]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_generic_error_when_the_account_is_pending(): void
    {
        // Un usuario recién invitado tiene una contraseña aleatoria no usable:
        // nunca puede adivinarla, así que cae en el mismo error genérico.
        User::factory()->unverified()->create([
            'email' => 'jorge@test.com',
            'password' => Hash::make(Str::random(32)),
        ]);

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'password',
            'client' => 'web',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);
    }

    public function test_login_fails_with_unknown_email(): void
    {
        $this->postJson('/api/login', [
            'email' => 'no-existe@test.com',
            'password' => 'password',
            'client' => 'web',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['code', 'error' => ['email', 'password', 'client']]);
    }

    public function test_login_requires_a_valid_email_format(): void
    {
        $this->postJson('/api/login', [
            'email' => 'no-es-un-correo',
            'password' => 'password',
            'client' => 'web',
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
                'client' => 'web',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'jorge@test.com',
            'password' => 'wrong-password',
            'client' => 'web',
        ])
            ->assertStatus(429)
            ->assertExactJson(['code' => 429, 'error' => Message::THROTTLE_REQUESTS_EXCEPTION])
            ->assertHeader('Retry-After');
    }

    /**
     * @return array<string, array{0: RoleEnum}>
     */
    public static function webRoles(): array
    {
        return ['superadmin' => [RoleEnum::SUPERADMIN], 'league_admin' => [RoleEnum::LEAGUE_ADMIN]];
    }

    /**
     * @return array<string, array{0: RoleEnum}>
     */
    public static function mobileRoles(): array
    {
        return ['referee' => [RoleEnum::REFEREE], 'manager' => [RoleEnum::MANAGER], 'player' => [RoleEnum::PLAYER]];
    }

    /**
     * @dataProvider webRoles
     */
    public function test_admin_roles_log_in_with_web_but_not_with_mobile(RoleEnum $role): void
    {
        $this->createUserWithRoles([$role]);

        $this->login('mobile')
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::ACCESS_ONLY_MOBILE_USERS]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->login('web')->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * @dataProvider mobileRoles
     */
    public function test_mobile_roles_log_in_with_mobile_but_not_with_web(RoleEnum $role): void
    {
        $this->createUserWithRoles([$role]);

        $this->login('web')
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::ACCESS_ONLY_ADMINS]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->login('mobile')->assertOk()->assertJsonStructure(['token']);
        $this->assertSame('mobile', PersonalAccessToken::query()->firstOrFail()->name);
    }

    public function test_user_with_roles_from_both_groups_logs_in_with_either_client(): void
    {
        $this->createUserWithRoles([RoleEnum::LEAGUE_ADMIN, RoleEnum::PLAYER]);

        $this->login('web')->assertOk();
        $this->login('mobile')->assertOk();
    }

    public function test_user_without_roles_is_rejected_for_both_clients(): void
    {
        $this->createUserWithRoles([]);

        $this->login('web')->assertStatus(403);
        $this->login('mobile')->assertStatus(403);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_client_is_required_and_must_be_web_or_mobile(): void
    {
        $this->createUserWithRoles([RoleEnum::SUPERADMIN]);

        $this->postJson('/api/login', ['email' => 'jorge@test.com', 'password' => 'password'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['client']]);

        foreach (['desktop', 'WEB', '', 1] as $invalid) {
            $this->login('web', ['client' => $invalid])->assertStatus(422)->assertJsonStructure(['error' => ['client']]);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_credentials_return_401_before_the_client_check(): void
    {
        $this->createUserWithRoles([RoleEnum::PLAYER]);

        $this->login('web', ['password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::CREDENTIALS_INVALID]);
    }

    public function test_inactive_account_takes_precedence_over_the_client_check(): void
    {
        $this->createUserWithRoles([RoleEnum::PLAYER], ['is_active' => false]);

        $this->login('web')
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::ACCOUNT_INACTIVE]);
    }
}
