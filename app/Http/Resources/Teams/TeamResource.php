<?php

namespace App\Http\Resources\Teams;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Team $resource
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $manager = $this->resource->manager;

        return [
            'id' => $this->resource->id,
            'league_id' => $this->resource->league_id,
            'name' => $this->resource->name,
            'sport' => $this->resource->sport->value,
            'sport_label' => $this->resource->sport->label(),
            'status' => $this->resource->status->value,
            'logo_url' => $this->resource->logoUrl(),
            'manager' => $manager === null ? null : [
                'id' => $manager->id,
                'user_code' => $manager->user_code,
                'first_name' => $manager->first_name,
                'last_name' => $manager->last_name,
                'email' => $manager->email,
                'phone' => $manager->phone,
                'photo_url' => $manager->photo_url,
                'account_status' => $manager->accountStatus()->value,
            ],
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
