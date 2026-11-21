<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_authenticated_user_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_code' => $user->user_code,
                'photo_url' => $user->photo_url,
                'birth_date' => null,
                'roles' => [],
            ]);
    }

    public function test_includes_the_birth_date_of_a_player(): void
    {
        $user = User::factory()->create();
        Player::factory()->create(['user_id' => $user->id, 'birth_date' => '1997-12-15']);
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('birth_date', '1997-12-15');
    }

    public function test_returns_the_roles_of_the_user_with_their_stable_code(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach([
            $this->createRole(RoleEnum::LEAGUE_ADMIN)->id,
            $this->createRole(RoleEnum::REFEREE)->id,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'roles')
            ->assertJsonPath('roles.0', [
                'id' => RoleEnum::LEAGUE_ADMIN->value,
                'code' => 'league_admin',
                'name' => RoleEnum::LEAGUE_ADMIN->label(),
            ])
            ->assertJsonPath('roles.1.code', 'referee');
    }

    public function test_ignores_roles_that_are_not_part_of_the_catalog(): void
    {
        $user = User::factory()->create();
        $unknown = Role::query()->forceCreate(['id' => 99, 'name' => 'Desconocido']);
        $user->roles()->attach([$unknown->id, $this->createRole(RoleEnum::PLAYER)->id]);
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'roles')
            ->assertJsonPath('roles.0.code', 'player');
    }

    public function test_fails_without_a_token(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_fails_with_an_invalid_token(): void
    {
        $this->getJson('/api/user', ['Authorization' => 'Bearer 1|no-existe'])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_fails_with_a_revoked_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->delete();

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    private function createRole(RoleEnum $role): Role
    {
        return Role::query()->forceCreate(['id' => $role->value, 'name' => $role->label()]);
    }
}
