<?php

namespace App\Http\Resources\Players;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jugador para el panel de administración (incluye la rama). La app solo la muestra al propio jugador y
 * el delegado nunca la ve.
 *
 * @property Player $resource
 */
class PlayerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;
        assert($user !== null);

        return [
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'birth_date' => $this->resource->birth_date->format('Y-m-d'),
            'gender' => $this->resource->gender->value,
        ];
    }
}
