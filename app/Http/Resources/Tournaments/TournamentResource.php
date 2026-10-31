<?php

namespace App\Http\Resources\Tournaments;

use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Tournament $resource
 */
class TournamentResource extends JsonResource
{
    /**
     * Los enums viajan como código; el front traduce las etiquetas.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'league_id' => $this->resource->league_id,
            'name' => $this->resource->name,
            'sport' => $this->resource->sport->value,
            'sport_label' => $this->resource->sport->label(),
            'gender' => $this->resource->gender->value,
            'min_age' => $this->resource->min_age,
            'max_age' => $this->resource->max_age,
            'max_roster_size' => $this->resource->max_roster_size,
            'min_matches_for_playoffs' => $this->resource->min_matches_for_playoffs,
            'rounds' => $this->resource->rounds,
            'match_duration_minutes' => $this->resource->match_duration_minutes,
            'walkover_goals' => $this->resource->walkover_goals,
            'tiebreakers' => $this->resource->tiebreakers,
            'start_date' => $this->resource->start_date->format('Y-m-d'),
            'status' => $this->resource->status->value,
            'playoffs_started' => $this->resource->playoffsStarted(),
            'created_at' => $this->resource->created_at,
        ];
    }
}
