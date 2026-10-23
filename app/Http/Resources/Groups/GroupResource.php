<?php

namespace App\Http\Resources\Groups;

use App\Core\Enum\TournamentGroupType;
use App\Helpers\PlayoffRounds;
use App\Models\PlayoffSource;
use App\Models\TournamentGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Grupo en el listado: las liguillas añaden fechas, fuentes, rondas y la fecha de la final.
 *
 * @property TournamentGroup $resource
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        $data = [
            'id' => $group->id,
            'name' => $group->name,
            'type' => $group->type->value,
            'teams_count' => $group->teams_count ?? 0,
        ];

        if ($group->type !== TournamentGroupType::PLAYOFF) {
            return $data;
        }

        $total = $group->totalTeams();

        return $data + [
            'start_date' => $group->start_date?->format('Y-m-d'),
            'round_interval_days' => $group->round_interval_days,
            'sources' => $group->sources->map(fn (PlayoffSource $source) => [
                'id' => $source->id,
                'source_group_id' => $source->source_group_id,
                'source_group_name' => $source->sourceGroup?->name,
                'position_start' => $source->position_start,
                'position_end' => $source->position_end,
            ])->values()->all(),
            'editable' => $group->tournament !== null && $group->editableIn($group->tournament),
            'total_teams' => $total,
            'rounds' => PlayoffRounds::count($total),
            'round_names' => PlayoffRounds::names($total),
            'round_dates' => $group->round_dates ?: null,
            'round_legs' => $group->legsPerRound(),
            'rounds_detail' => $group->roundsDetail(),
            'final_date' => $group->finalDate(),
        ];
    }
}
