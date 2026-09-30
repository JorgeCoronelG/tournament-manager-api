<?php

namespace App\Http\Resources\Users;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $known = RoleEnum::from((int) $this->resource->id);

        return [
            'id' => $known->value,
            'code' => $known->code(),
            'name' => $this->resource->name,
        ];
    }
}
