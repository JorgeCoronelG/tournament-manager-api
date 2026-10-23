<?php

namespace App\Http\Resources\Matches;

use App\Models\GameMatch;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Partido con su grupo y los dos equipos.
 *
 * @property GameMatch $resource
 */
class MatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $match = $this->resource;

        return [
            'id' => $match->id,
            'group_id' => $match->group_id,
            'group_name' => $match->group?->name,
            'matchday' => $match->matchday,
            'round_number' => $match->round_number,
            'phase' => $match->phase->value,
            'status' => $match->status->value,
            'match_datetime' => $match->match_datetime?->toIso8601String(),
            'home' => $this->side($match->home),
            'away' => $this->side($match->away),
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'home_penalties' => $match->home_penalties,
            'away_penalties' => $match->away_penalties,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function side(?Registration $registration): ?array
    {
        $team = $registration?->team;

        if ($registration === null || $team === null) {
            return null;
        }

        return [
            'registration_id' => $registration->id,
            'team_id' => $registration->team_id,
            'team_name' => $team->name,
            'logo_url' => $team->logoUrl(),
        ];
    }
}
