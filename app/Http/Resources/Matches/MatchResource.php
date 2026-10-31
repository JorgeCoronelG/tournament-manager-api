<?php

namespace App\Http\Resources\Matches;

use App\Core\Enum\RegistrationStatus;
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
            'tie_id' => $match->tie_id,
            'round_index' => $match->round_index,
            'leg' => $match->leg,
            'bracket_position' => $match->bracket_position,
            'scheduled_date' => $match->scheduled_date?->toDateString(),
            'phase' => $match->phase->value,
            'status' => $match->status->value,
            'match_datetime' => $match->match_datetime?->toIso8601String(),
            'field' => $match->field_id === null ? null : ['id' => $match->field_id, 'name' => $match->field?->name],
            'original_match_datetime' => $match->original_match_datetime?->toIso8601String(),
            'schedule_locked' => $match->schedule_locked,
            'referee' => $match->referee_user_id === null ? null : ['id' => $match->referee_user_id, 'name' => $match->referee === null ? null : trim($match->referee->first_name.' '.$match->referee->last_name)],
            'home' => $this->side($match->home),
            'away' => $this->side($match->away),
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'home_penalties' => $match->home_penalties,
            'away_penalties' => $match->away_penalties,
            'home_withdrawn' => $match->home?->status === RegistrationStatus::WITHDRAWN,
            'away_withdrawn' => $match->away?->status === RegistrationStatus::WITHDRAWN,
            'is_walkover' => $match->is_walkover,
            'walkover_cause' => $match->walkover_cause?->value,
            'walkover_loser_registration_id' => $match->walkover_loser_registration_id,
            'walkover_reason' => $match->walkover_reason,
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
