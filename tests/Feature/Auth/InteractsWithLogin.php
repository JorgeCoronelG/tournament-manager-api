<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait InteractsWithLogin
{
    /**
     * Usuario jorge@test.com (contraseña `password`) con los roles indicados.
     *
     * @param  list<RoleEnum>  $roles
     * @param  array<string, mixed>  $attributes
     */
    private function createUserWithRoles(array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['email' => 'jorge@test.com'], $attributes));

        foreach ($roles as $role) {
            $user->roles()->attach(Role::query()->find($role->value)
                ?? Role::query()->forceCreate(['id' => $role->value, 'name' => $role->label()]));
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function login(string $client, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/login', array_merge([
            'email' => 'jorge@test.com',
            'password' => 'password',
            'client' => $client,
        ], $overrides));
    }
}
