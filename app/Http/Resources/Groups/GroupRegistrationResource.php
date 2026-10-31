<?php

namespace App\Http\Resources\Groups;

use App\Core\Enum\RegistrationStatus;
use App\Http\Resources\Registrations\RegistrationTeamResource;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscripción vista desde los grupos (activa o dada de baja, que conserva su grupo): su id, estado, equipo (nombre, logo) y delegado.
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
            'status' => $this->resource->status->value,
            'withdrawn' => $this->resource->status === RegistrationStatus::WITHDRAWN,
            'team' => new RegistrationTeamResource($this->resource->team),
        ];
    }
}
