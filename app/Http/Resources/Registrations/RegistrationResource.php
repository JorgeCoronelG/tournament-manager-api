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
            'created_at' => $this->resource->created_at,
            'team' => new RegistrationTeamResource($this->resource->team),
        ];
    }
}
