<?php

namespace App\Http\Resources\Players;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jugador para el listado del panel, siempre respecto a una liga (`is_banned` y `active_teams` se cargan para ella).
 * Nunca incluye el correo. La rama es la de la liga del contexto: el jugador es el mismo en todas.
 *
 * @property Player $resource
 */
class PlayerListResource extends JsonResource
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
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'photo_url' => $user->photo_url,
            'user_code' => $user->user_code,
            'gender' => $this->resource->gender->value,
            'birth_date' => $this->resource->birth_date->format('Y-m-d'),
            'age' => $this->resource->birth_date->age,
            'phone' => $user->phone,
            'is_banned' => (bool) $this->resource->getAttribute('is_banned'),
            'active_teams' => $this->resource->rosters->map(fn ($roster) => [
                'team' => $roster->registration?->team?->name,
                'tournament' => $roster->tournament?->name,
            ])->values()->all(),
        ];
    }
}
