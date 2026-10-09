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
            'gender' => $this->resource->gender->value,
            'min_age' => $this->resource->min_age,
            'max_age' => $this->resource->max_age,
            'start_date' => $this->resource->start_date->format('Y-m-d'),
            'status' => $this->resource->status->value,
            'created_at' => $this->resource->created_at,
        ];
    }
}
