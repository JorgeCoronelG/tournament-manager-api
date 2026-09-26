<?php

namespace Tests\Feature\Core;

use App\Core\Enum\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sanctum con tokens reales (no Sanctum::actingAs) junto con el middleware Permission.
 */
class SanctumAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/_test/me', fn (Request $request) => ['email' => $request->user()?->email]);
            Route::post('/_test/logout', function (Request $request) {
                /** @var User $user */
                $user = $request->user();
                $user->currentAccessToken()->delete();

                return response()->noContent();
            });
            Route::get('/_test/admin', fn () => ['ok' => true])->middleware('permission:1');
            Route::get('/_test/staff', fn () => ['ok' => true])->middleware('permission:1,2');
        });
    }

    private function userWithRole(?int $roleId): User
    {
        $user = User::factory()->create();

        if ($roleId !== null) {
            $user->roles()->attach(Role::factory()->create(['id' => $roleId]));
        }

        return $user;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_request_without_token_is_unauthenticated(): void
    {
        $this->getJson('/_test/me')
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_request_with_an_invalid_token_is_unauthenticated(): void
    {
        $this->getJson('/_test/me', ['Authorization' => 'Bearer 1|no-existe'])->assertUnauthorized();
    }

    public function test_valid_token_authenticates_the_user(): void
    {
        $user = $this->userWithRole(1);

        $this->getJson('/_test/me', $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_deleting_the_token_revokes_access(): void
    {
        $user = $this->userWithRole(1);
        $headers = $this->bearer($user);

        $this->getJson('/_test/me', $headers)->assertOk();

        $user->tokens()->delete();
        $this->app['auth']->forgetGuards();

        $this->getJson('/_test/me', $headers)->assertUnauthorized();
    }

    public function test_logout_deletes_only_the_current_token(): void
    {
        $user = $this->userWithRole(1);
        $current = $this->bearer($user);
        $other = $this->bearer($user);

        $this->postJson('/_test/logout', [], $current)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/_test/me', $other)->assertOk();
    }

    public function test_permission_allows_the_matching_role(): void
    {
        $this->getJson('/_test/admin', $this->bearer($this->userWithRole(1)))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_permission_denies_another_role_with_403(): void
    {
        $this->getJson('/_test/admin', $this->bearer($this->userWithRole(2)))
            ->assertForbidden()
            ->assertExactJson(['code' => 403, 'error' => Message::AUTHORIZATION_EXCEPTION]);
    }

    public function test_permission_denies_a_user_without_role_with_403(): void
    {
        $this->getJson('/_test/admin', $this->bearer($this->userWithRole(null)))->assertForbidden();
    }

    public function test_permission_accepts_a_list_of_roles(): void
    {
        $this->getJson('/_test/staff', $this->bearer($this->userWithRole(2)))->assertOk();
    }
}
