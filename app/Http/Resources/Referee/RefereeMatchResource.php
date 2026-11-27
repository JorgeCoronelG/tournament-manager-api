<?php

namespace App\Http\Resources\Referee;

use App\Models\GameMatch;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Partido en la lista del árbitro. `knockout` le dice a la app si un empate se define con penales y, en la vuelta,
 * cuántos goles trae cada equipo de la ida (ya acomodados como local y visitante de este partido).
 *
 * @property GameMatch $resource
 */
class RefereeMatchResource extends JsonResource
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
            'tournament' => [
                'id' => $tournament?->id,
                'name' => $tournament?->name,
                'status' => $tournament?->status->value,
                'match_duration_minutes' => $tournament?->match_duration_minutes,
            ],
            'phase' => $match->phase->value,
            'matchday' => $match->matchday,
            'match_datetime' => $match->match_datetime?->toIso8601String(),
            'status' => $match->status->value,
            'field' => $match->field === null ? null : ['id' => $match->field->id, 'name' => $match->field->name],
            'home' => $this->side($match->home),
            'away' => $this->side($match->away),
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'home_penalties' => $match->home_penalties,
            'away_penalties' => $match->away_penalties,
            'knockout' => $this->knockout($match),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function side(?Registration $registration): ?array
    {
        if ($registration === null) {
            return null;
        }

        $team = $registration->team;

        return [
            'registration_id' => $registration->id,
            'team' => [
                'id' => $team?->id,
                'name' => $team?->name,
                'logo_url' => $team?->logoUrl(),
            ],
        ];
    }

    /**
     * @return array{legs: int, leg: int|null, first_leg: array{home_goals: int, away_goals: int}|null}|null
     */
    private function knockout(GameMatch $match): ?array
    {
        $tie = $match->tie;

        if ($tie === null) {
            return null;
        }

        $legs = $tie->matches->count();
        $first = $tie->matches->first();
        $firstLeg = null;

        if ($legs === 2 && $match->leg === 2 && $first instanceof GameMatch && $first->home_score !== null) {
            $sameHome = $first->home_registration_id === $match->home_registration_id;
            $firstLeg = [
                'home_goals' => (int) ($sameHome ? $first->home_score : $first->away_score),
                'away_goals' => (int) ($sameHome ? $first->away_score : $first->home_score),
            ];
        }

        return ['legs' => $legs, 'leg' => $match->leg, 'first_leg' => $firstLeg];
    }
}
