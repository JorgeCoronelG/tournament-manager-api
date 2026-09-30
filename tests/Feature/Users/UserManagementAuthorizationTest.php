<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list users' => ['GET', '/api/users'],
            'list roles' => ['GET', '/api/roles'],
            'show user' => ['GET', '/api/users/1'],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_fails_with_401_without_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_fails_with_403_for_a_non_superadmin_role(string $method, string $uri): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));
        $token = $user->createToken('test')->plainTextToken;

        $this->json($method, $uri, [], $this->authHeader($token))
            ->assertStatus(403)
            ->assertExactJson(['code' => 403, 'error' => Message::AUTHORIZATION_EXCEPTION]);
    }

    public function test_allows_a_superadmin_to_list_users(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/users', $this->authHeader($token))->assertOk();
    }
}
