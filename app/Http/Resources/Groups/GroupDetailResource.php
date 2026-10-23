<?php

namespace App\Http\Resources\Groups;

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
            'teams_count' => $teams->count(),
            'teams' => GroupRegistrationResource::collection($teams)->resolve($request),
        ]);
    }
}
