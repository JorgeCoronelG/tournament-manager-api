<?php

namespace App\Http\Resources\Leagues;

use App\Models\League;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property League $resource
 */
class LeagueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $admin Siempre existe: el FK lo exige y un encargado con ligas no se puede eliminar. */
        $admin = $this->resource->admin;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'admin' => [
                'id' => $admin->id,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'email' => $admin->email,
                'status' => $admin->accountStatus()->value,
            ],
            'created_at' => $this->resource->created_at,
        ];
    }
}
