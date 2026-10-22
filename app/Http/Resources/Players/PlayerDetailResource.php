<?php

namespace App\Http\Resources\Players;

use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Datos del listado más el historial de plantillas y los vetos del jugador en la liga del contexto.
 *
 * @property Player $resource
 */
class PlayerDetailResource extends PlayerListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Roster> $rosters */
        $rosters = $this->resource->getRelation('leagueRosters');
        /** @var Collection<int, PlayerLeagueBan> $bans */
        $bans = $this->resource->getRelation('leagueBans');

        return parent::toArray($request) + [
            'rosters' => $rosters->map(fn (Roster $roster) => [
                'id' => $roster->id,
                'tournament' => $roster->tournament?->name,
                'team' => $roster->registration?->team?->name,
                'jersey_number' => $roster->jersey_number,
                'status' => $roster->status->value,
                'requested_at' => $roster->requested_at,
                'removed_by' => $this->person($roster->removedBy),
                'removed_at' => $roster->removed_at,
                'removal_reason' => $roster->removal_reason,
            ])->values()->all(),
            'bans' => $bans->map(fn ($ban) => [
                'id' => $ban->id,
                'reason' => $ban->reason,
                'banned_by' => $this->person($ban->bannedBy),
                'banned_at' => $ban->created_at,
                'lifted_by' => $this->person($ban->liftedBy),
                'lifted_at' => $ban->lifted_at,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => trim($user->first_name.' '.$user->last_name)];
    }
}
