<?php

namespace App\Http\Resources\Teams;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista mínima para confirmar a quién se va a asignar: sin correo ni teléfono, con la misma forma que la búsqueda de árbitros.
 *
 * @property User $resource
 */
class TeamManagerLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->resource->id,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'photo_url' => $this->resource->photo_url,
        ];
    }
}
