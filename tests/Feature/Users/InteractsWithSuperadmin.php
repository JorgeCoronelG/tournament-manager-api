<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;

trait InteractsWithSuperadmin
{
    private function createRole(RoleEnum $role): Role
    {
        return Role::query()->find($role->value)
            ?? Role::query()->forceCreate(['id' => $role->value, 'name' => $role->label()]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function actingAsSuperadmin(): array
    {
        $superadmin = User::factory()->create();
        $superadmin->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));

        return [$superadmin, $superadmin->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
