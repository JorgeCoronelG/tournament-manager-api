<?php

namespace App\Http\Resources\Rosters;

use App\Core\Enum\TournamentStatus;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscripción de un equipo del delegado: a qué torneo va, cuántos jugadores lleva y si aún puede mandar
 * solicitudes (`rosters_open`: torneo en planeación o en curso y sin liguilla).
 *
 * @property Registration $resource
 */
class ManagerRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $team = $this->resource->team;
        $tournament = $this->resource->tournament;
        assert($team !== null && $tournament !== null);
        $league = $tournament->league;

        $rostersOpen = in_array($tournament->status, [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS], true)
            && ! $tournament->playoffsStarted();

        return [
            'id' => $this->resource->id,
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'logo_url' => $team->logoUrl(),
            ],
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status->value,
                'start_date' => $tournament->start_date->toDateString(),
                'max_roster_size' => $tournament->max_roster_size,
            ],
            'league' => $league === null ? null : ['id' => $league->id, 'name' => $league->name],
            'rosters_open' => $rostersOpen,
            'active_count' => (int) $this->resource->getAttribute('active_count'),
            'pending_count' => (int) $this->resource->getAttribute('pending_count'),
        ];
    }
}
