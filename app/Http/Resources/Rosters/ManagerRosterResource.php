<?php

namespace App\Http\Resources\Rosters;

use App\Models\Roster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la plantilla para el delegado en la app. Sin rama, correo ni teléfono del jugador.
 *
 * @property Roster $resource
 */
class ManagerRosterResource extends JsonResource
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
            ],
            'jersey_number' => $this->resource->jersey_number,
            'status' => $this->resource->status->value,
            'requested_at' => $this->resource->requested_at,
            'responded_at' => $this->resource->responded_at,
            'removed_at' => $this->resource->removed_at,
        ];
    }
}
