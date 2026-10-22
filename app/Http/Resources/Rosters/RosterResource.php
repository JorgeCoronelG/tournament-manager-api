<?php

namespace App\Http\Resources\Rosters;

use App\Models\Roster;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del roster para el panel. Los enums viajan como código; el front traduce las etiquetas.
 *
 * @property Roster $resource
 */
class RosterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $player = $this->resource->player;
        assert($player !== null);
        $user = $player->user;
        assert($user !== null);

        return [
            'id' => $this->resource->id,
            'player' => [
                'id' => $player->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'photo_url' => $user->photo_url,
                'user_code' => $user->user_code,
                'gender' => $player->gender->value,
            ],
            'jersey_number' => $this->resource->jersey_number,
            'status' => $this->resource->status->value,
            'requested_at' => $this->resource->requested_at,
            'requested_by' => $this->person($this->resource->requestedBy),
            'responded_at' => $this->resource->responded_at,
            'removed_by' => $this->person($this->resource->removedBy),
            'removed_at' => $this->resource->removed_at,
            'removal_reason' => $this->resource->removal_reason,
            'rejoin_authorized_at' => $this->resource->rejoin_authorized_at,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => trim($user->first_name.' '.$user->last_name)];
    }
}
