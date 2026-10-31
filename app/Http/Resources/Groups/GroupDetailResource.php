<?php

namespace App\Http\Resources\Groups;

use App\Core\Enum\RegistrationStatus;
use App\Models\TournamentGroup;
use Illuminate\Http\Request;

/**
 * Detalle de un grupo: lo del listado más los equipos asignados.
 *
 * @property TournamentGroup $resource
 */
class GroupDetailResource extends GroupResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $teams = $this->resource->registrations;

        return array_merge(parent::toArray($request), [
            // Los equipos dados de baja se listan con su grupo, pero no cuentan como equipos que juegan.
            'teams_count' => $teams->where('status', RegistrationStatus::ACTIVE)->count(),
            'teams' => GroupRegistrationResource::collection($teams)->resolve($request),
        ]);
    }
}
