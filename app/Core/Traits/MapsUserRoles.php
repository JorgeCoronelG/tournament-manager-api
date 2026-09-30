<?php

namespace App\Core\Traits;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

trait MapsUserRoles
{
    /**
     * Roles del catálogo que la app conoce; ignora cualquier otro id.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function mapRoles(Collection $roles): array
    {
        return $roles
            ->map(function (Role $role): ?array {
                $known = RoleEnum::tryFrom((int) $role->id);

                return $known === null
                    ? null
                    : ['id' => (int) $known->value, 'code' => $known->code(), 'name' => $role->name];
            })
            ->filter()
            ->values()
            ->all();
    }
}
