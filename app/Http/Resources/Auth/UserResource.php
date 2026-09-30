<?php

namespace App\Http\Resources\Auth;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'email' => $this->resource->email,
            'photo_url' => $this->resource->photo_url,
            'roles' => $this->roles(),
        ];
    }

    /**
     * Roles del catálogo que la app conoce; ignora cualquier otro id.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function roles(): array
    {
        return $this->resource->roles
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
