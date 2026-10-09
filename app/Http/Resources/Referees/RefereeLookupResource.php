<?php

namespace App\Http\Resources\Referees;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista mínima para confirmar a quién se va a agregar: sin correo ni teléfono.
 *
 * @property array{user: User, already_in_league: bool} $resource
 */
class RefereeLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        return [
            'user_id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'photo_url' => $user->photo_url,
            'already_in_league' => $this->resource['already_in_league'],
        ];
    }
}
