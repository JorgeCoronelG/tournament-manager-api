<?php

namespace App\Repositories;

use App\Contracts\Repositories\PlayerRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Player;
use App\Models\PlayerGenderChange;
use App\Models\Roster;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Player>
 */
class PlayerRepository extends BaseRepository implements PlayerRepositoryInterface
{
    public function __construct(Player $entity)
    {
        parent::__construct($entity);
    }

    public function lockWithRosters(int $playerId): Player
    {
        $player = $this->entity->newQuery()->lockForUpdate()->findOrFail($playerId);
        Roster::query()->where('player_id', $playerId)->lockForUpdate()->get();

        return $player;
    }

    public function hasRosterInLeague(int $playerId, int $leagueId): bool
    {
        return Roster::query()
            ->where('player_id', $playerId)
            ->whereHas('tournament', fn (Builder $tournament) => $tournament->withTrashed()->where('league_id', $leagueId))
            ->exists();
    }

    public function openTournamentsWithActiveRoster(int $playerId): Collection
    {
        return Tournament::query()
            ->whereIn('status', [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS])
            ->whereIn('id', Roster::query()
                ->where('player_id', $playerId)
                ->where('status', RosterStatus::ACTIVE)
                ->select('tournament_id'))
            ->orderBy('name')
            ->get();
    }

    public function updateGender(Player $player, PlayerGender $gender): Player
    {
        $player->forceFill(['gender' => $gender]);
        $player->saveOrFail();

        return $player;
    }

    public function recordGenderChange(Player $player, int $changedByUserId, PlayerGender $old, PlayerGender $new): void
    {
        $change = new PlayerGenderChange;
        $change->forceFill([
            'player_id' => $player->id,
            'changed_by_user_id' => $changedByUserId,
            'old_gender' => $old,
            'new_gender' => $new,
        ]);
        $change->saveOrFail();
    }
}
