<?php

namespace App\Http\Resources\Registrations;

use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Registration $resource
 */
class RegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'group_id' => $this->resource->group_id,
            'group' => $this->whenLoaded('group', fn () => $this->resource->group === null ? null : ['id' => $this->resource->group->id, 'name' => $this->resource->group->name]),
            'created_at' => $this->resource->created_at,
            'withdrawn_at' => $this->resource->withdrawn_at,
            'withdrawn_reason' => $this->resource->withdrawn_reason,
            'team' => new RegistrationTeamResource($this->resource->team),
        ];
    }
}
