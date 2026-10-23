<?php

namespace App\Http\Resources\Groups;

use App\Http\Resources\Registrations\RegistrationTeamResource;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscripción activa vista desde los grupos: su id, el equipo (nombre, logo) y el delegado.
 *
 * @property Registration $resource
 */
class GroupRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team' => new RegistrationTeamResource($this->resource->team),
        ];
    }
}
