<?php

namespace App\Http\Resources\Referees;

use App\Models\LeagueReferee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un árbitro dentro de una liga: datos del usuario (id = user id) y estado del vínculo.
 *
 * @property LeagueReferee $resource
 */
class RefereeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource->user;

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_code' => $user->user_code,
            'photo_url' => $user->photo_url,
            'account_status' => $user->accountStatus()->value,
            'status' => $this->resource->status->value,
            'created_at' => $this->resource->created_at,
        ];
    }
}
