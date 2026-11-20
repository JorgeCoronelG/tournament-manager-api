<?php

namespace App\Http\Resources\Rosters;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo único que ve el delegado al buscar por clave: nombre y foto. Nunca correo, teléfono ni rama.
 *
 * @property Player $resource
 */
class PlayerLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;
        assert($user !== null);

        return [
            'player_id' => $this->resource->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'photo_url' => $user->photo_url,
        ];
    }
}
