<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
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
                'photo_url' => $user->photo_url,
            ]);
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
}
