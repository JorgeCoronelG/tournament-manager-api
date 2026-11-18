<?php

namespace App\Http\Resources\Tournaments;

use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del historial: requiere `end_date`, `teams_count` y `playoffs_count` calculados por el repositorio.
 *
 * @property Tournament $resource
 */
class TournamentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'start_date' => $this->resource->start_date->format('Y-m-d'),
            'end_date' => $this->resource->getAttribute('end_date'),
            'teams_count' => $this->resource->getAttributeValue('teams_count'),
            'playoffs_count' => $this->resource->getAttributeValue('playoffs_count'),
        ];
    }
}
