<?php

namespace App\Http\Resources\Users;

use App\Core\Enum\UserAccountStatus;
use App\Core\Traits\MapsUserRoles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class UserResource extends JsonResource
{
    use MapsUserRoles;

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
            'phone' => $this->resource->phone,
            'user_code' => $this->resource->user_code,
            'photo_url' => $this->resource->photo_url,
            'is_active' => $this->resource->is_active,
            'email_verified_at' => $this->resource->email_verified_at,
            'status' => $this->status()->value,
            'roles' => $this->mapRoles($this->resource->roles),
            'created_at' => $this->resource->created_at,
        ];
    }

    private function status(): UserAccountStatus
    {
        if ($this->resource->email_verified_at === null) {
            return UserAccountStatus::PENDING;
        }

        return $this->resource->is_active ? UserAccountStatus::ACTIVE : UserAccountStatus::INACTIVE;
    }
}
