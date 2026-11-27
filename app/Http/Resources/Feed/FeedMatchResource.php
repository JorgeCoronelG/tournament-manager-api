<?php

namespace App\Http\Resources\Feed;

use App\Models\GameMatch;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Partido en Inicio de la app móvil (todos los roles): sin árbitro, QR ni datos personales.
 *
 * @property GameMatch $resource
 */
class FeedMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $match = $this->resource;
        $tournament = $match->tournament;
        $league = $tournament?->league;

        return [
            'id' => $match->id,
            'league' => ['id' => $league?->id, 'name' => $league?->name],
            'tournament' => ['id' => $tournament?->id, 'name' => $tournament?->name],
            'phase' => $match->phase->value,
            'matchday' => $match->matchday,
            'leg' => $match->leg,
            'match_datetime' => $match->match_datetime?->toIso8601String(),
            'status' => $match->status->value,
            'is_walkover' => $match->is_walkover,
            'field' => $match->field === null ? null : ['id' => $match->field->id, 'name' => $match->field->name],
            'home' => $this->side($match->home),
            'away' => $this->side($match->away),
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'home_penalties' => $match->home_penalties,
            'away_penalties' => $match->away_penalties,
        ];
    }

    /**
     * @return array{registration_id: int, team: array{id: int|null, name: string|null, logo_url: string|null}}|null
     */
    private function side(?Registration $registration): ?array
    {
        if ($registration === null) {
            return null;
        }

        $team = $registration->team;

        return [
            'registration_id' => $registration->id,
            'team' => ['id' => $team?->id, 'name' => $team?->name, 'logo_url' => $team?->logoUrl()],
        ];
    }
}
