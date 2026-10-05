<?php

namespace Tests\Feature\Leagues;

use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueAuthorizationTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['getJson', '/api/leagues'],
            'show' => ['getJson', '/api/leagues/1'],
            'store' => ['postJson', '/api/leagues'],
            'update' => ['putJson', '/api/leagues/1'],
            'destroy' => ['deleteJson', '/api/leagues/1'],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_401_without_token(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertStatus(401);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_403_to_other_roles(string $method, string $uri): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id]);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $method === 'getJson'
            ? $this->getJson($uri, $this->authHeader($token))
            : $this->{$method}($uri, [], $this->authHeader($token));

        $response->assertStatus(403);
    }
}
