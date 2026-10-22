<?php

namespace App\Repositories;

use App\Contracts\Repositories\PlayerRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Player;
use App\Models\PlayerGenderChange;
use App\Models\PlayerLeagueBan;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

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

    public function cancelPendingRostersNotAccepting(int $playerId, PlayerGender $gender): int
    {
        return Roster::query()
            ->where('player_id', $playerId)
            ->where('status', RosterStatus::PENDING)
            ->whereIn('tournament_id', Tournament::query()
                ->whereNotIn('gender', [$gender->value, TournamentGender::MIXED->value])
                ->select('id'))
            ->update(['status' => RosterStatus::CANCELLED, 'responded_at' => now()]);
    }

    public function paginateForLeague(int $leagueId, bool $onlyWithRoster, ListQuery $query, ?string $search, ?PlayerGender $gender, ?bool $banned): LengthAwarePaginator
    {
        $builder = $this->entity->newQuery()
            ->when($onlyWithRoster, fn (Builder $players) => $players->whereHas('rosters', fn (Builder $rosters) => $rosters
                ->whereHas('tournament', fn (Builder $tournament) => $tournament->withTrashed()->where('league_id', $leagueId))))
            ->when($gender !== null, fn (Builder $players) => $players->where('gender', $gender))
            ->when($banned !== null, function (Builder $players) use ($leagueId, $banned) {
                $banned
                    ? $players->whereHas('leagueBans', fn (Builder $bans) => $bans->where('league_id', $leagueId)->whereNull('lifted_at'))
                    : $players->whereDoesntHave('leagueBans', fn (Builder $bans) => $bans->where('league_id', $leagueId)->whereNull('lifted_at'));
            })
            ->when($search !== null, function (Builder $players) use ($search) {
                $players->whereHas('user', function (Builder $user) use ($search) {
                    $user->where(function (Builder $group) use ($search) {
                        $group->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhere('user_code', 'LIKE', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                    });
                });
            });

        $this->applyListSort($builder, $query->sort ?? 'name');
        $this->withLeagueData($builder, $leagueId);

        return $query->paginate($builder->orderBy('players.id'));
    }

    public function loadForLeague(Player $player, int $leagueId): Player
    {
        $player->loadExists(['leagueBans as is_banned' => fn (Builder $bans) => $bans->where('league_id', $leagueId)->whereNull('lifted_at')]);
        $player->load([
            'user',
            'rosters' => fn ($rosters) => $this->activeInLeague($rosters, $leagueId),
        ]);

        return $player;
    }

    public function loadDetailForLeague(Player $player, int $leagueId): Player
    {
        $this->loadForLeague($player, $leagueId);

        $player->setRelation('leagueRosters', Roster::query()
            ->where('player_id', $player->id)
            ->whereHas('tournament', fn (Builder $tournament) => $tournament->withTrashed()->where('league_id', $leagueId))
            ->with([
                'tournament' => fn ($tournament) => $tournament->withTrashed(),
                'registration.team',
                'removedBy',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get());

        $player->setRelation('leagueBans', PlayerLeagueBan::query()
            ->where('player_id', $player->id)
            ->where('league_id', $leagueId)
            ->with(['bannedBy', 'liftedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get());

        return $player;
    }

    public function lockActiveBan(int $playerId, int $leagueId): ?PlayerLeagueBan
    {
        return PlayerLeagueBan::query()
            ->where('player_id', $playerId)
            ->where('league_id', $leagueId)
            ->whereNull('lifted_at')
            ->lockForUpdate()
            ->first();
    }

    public function createBan(int $playerId, int $leagueId, int $bannedByUserId, string $reason): PlayerLeagueBan
    {
        $ban = new PlayerLeagueBan;
        $ban->forceFill([
            'player_id' => $playerId,
            'league_id' => $leagueId,
            'banned_by_user_id' => $bannedByUserId,
            'reason' => $reason,
        ]);
        $ban->saveOrFail();

        return $ban;
    }

    public function liftBan(PlayerLeagueBan $ban, int $liftedByUserId): PlayerLeagueBan
    {
        $ban->forceFill(['lifted_at' => now(), 'lifted_by_user_id' => $liftedByUserId]);
        $ban->saveOrFail();

        return $ban;
    }

    /**
     * @param  Builder<Player>  $builder
     */
    private function withLeagueData(Builder $builder, int $leagueId): void
    {
        $builder->withExists(['leagueBans as is_banned' => fn (Builder $bans) => $bans->where('league_id', $leagueId)->whereNull('lifted_at')])
            ->with([
                'user',
                'rosters' => fn ($rosters) => $this->activeInLeague($rosters, $leagueId),
            ]);
    }

    /**
     * Rosters active en torneos de la liga, con su torneo y equipo.
     *
     * @param  Relation<Roster, Player, *>  $rosters
     */
    private function activeInLeague($rosters, int $leagueId): void
    {
        $rosters->where('status', RosterStatus::ACTIVE)
            ->whereHas('tournament', fn (Builder $tournament) => $tournament->where('league_id', $leagueId))
            ->with(['tournament', 'registration.team']);
    }

    /**
     * @param  Builder<Player>  $builder
     *
     * @throws CustomErrorException
     */
    private function applyListSort(Builder $builder, string $sort): void
    {
        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $columns = match (ltrim($field, '-')) {
                'name' => ['first_name', 'last_name'],
                'user_code' => ['user_code'],
                default => throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST),
            };

            foreach ($columns as $column) {
                $builder->orderBy(User::query()->select($column)->whereColumn('users.id', 'players.user_id'), $direction);
            }
        }
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
