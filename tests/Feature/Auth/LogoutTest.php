<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearer($user);

        $this->postJson('/api/logout', [], $headers)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_only_revokes_the_current_token_not_other_sessions(): void
    {
        $user = User::factory()->create();
        $current = $this->bearer($user);
        $other = $this->bearer($user);

        $this->postJson('/api/logout', [], $current)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user', $other)->assertOk();
    }

    public function test_revoked_token_can_no_longer_be_used(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearer($user);

        $this->postJson('/api/logout', [], $headers)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user', $headers)
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }
}
