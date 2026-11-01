<?php

namespace App\Http\Resources\Rosters;

use App\Models\Roster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Solicitud que ve el jugador en la app: qué equipo lo invita, a qué torneo y con qué dorsal.
 *
 * @property Roster $resource
 */
class PlayerRosterRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $team = $this->resource->registration?->team;
        $tournament = $this->resource->tournament;
        $league = $tournament?->league;
        $requestedBy = $this->resource->requestedBy;

        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'jersey_number' => $this->resource->jersey_number,
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name, 'logo_url' => $team->logoUrl()],
            'tournament' => $tournament === null ? null : ['id' => $tournament->id, 'name' => $tournament->name],
            'league' => $league === null ? null : ['id' => $league->id, 'name' => $league->name],
            'requested_by' => $requestedBy === null ? null : trim($requestedBy->first_name.' '.$requestedBy->last_name),
            'requested_at' => $this->resource->requested_at,
        ];
    }
}
