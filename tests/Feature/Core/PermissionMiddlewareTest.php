<?php

namespace Tests\Feature\Core;

use App\Http\Middleware\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUserWithRole(int $roleId): void
    {
        $role = Role::factory()->create(['id' => $roleId]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->actingAs($user);
    }

    private function handle(string|int ...$roleIds): Response
    {
        return (new Permission)->handle(
            Request::create('/'),
            fn () => new Response('ok'),
            ...$roleIds
        );
    }

    public function test_allows_when_role_id_matches_and_types_are_equal(): void
    {
        $this->actAsUserWithRole(1);

        $this->assertSame('ok', $this->handle(1)->getContent());
    }

    public function test_denies_when_role_does_not_match(): void
    {
        $this->actAsUserWithRole(2);

        $this->expectException(AuthorizationException::class);
        $this->handle(1);
    }

    public function test_allows_when_parameter_is_string_as_in_real_routes(): void
    {
        // Los parámetros de middleware ("permission:1") llegan como string.
        $this->actAsUserWithRole(1);

        $this->assertSame('ok', $this->handle('1')->getContent());
    }

    public function test_allows_when_role_is_any_of_several_parameters(): void
    {
        $this->actAsUserWithRole(2);

        $this->assertSame('ok', $this->handle('1', '2', '3')->getContent());
    }

    public function test_user_without_role_is_denied(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        $this->handle(1);
    }

    public function test_unauthenticated_user_is_rejected_with_authentication_exception(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->handle(1);
    }
}
