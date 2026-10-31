<?php

namespace App\Repositories;

use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<Roster>
 */
class RosterRepository extends BaseRepository implements RosterRepositoryInterface
{
    public function __construct(Roster $entity)
    {
        parent::__construct($entity);
    }

    public function lockById(int $rosterId): Roster
    {
        return $this->entity->newQuery()->lockForUpdate()->findOrFail($rosterId);
    }

    public function lockByPlayerInTournament(int $playerId, int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('player_id', $playerId)
            ->where('tournament_id', $tournamentId)
            ->lockForUpdate()
            ->get();
    }

    public function lockOpenByRegistration(int $registrationId): Collection
    {
        return $this->currentTeam($this->entity->newQuery(), $registrationId)
            ->whereIn('status', [RosterStatus::PENDING, RosterStatus::ACTIVE])
            ->lockForUpdate()
            ->get();
    }

    public function playerBannedInLeague(int $playerId, int $leagueId): bool
    {
        return DB::table('player_league_bans')
            ->where('player_id', $playerId)
            ->where('league_id', $leagueId)
            ->whereNull('lifted_at')
            ->exists();
    }

    public function largestOpenRoster(int $tournamentId): ?array
    {
        $row = $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->whereIn('status', [RosterStatus::PENDING, RosterStatus::ACTIVE])
            ->select('registration_id', DB::raw('COUNT(*) as players'))
            ->groupBy('registration_id')
            ->orderByDesc('players')
            ->first();

        if ($row === null) {
            return null;
        }

        $registrationId = $row->registration_id;
        $registration = Registration::query()->find($registrationId);
        $team = $registration === null ? null : Team::query()->withTrashed()->find($registration->team_id);
        $players = $row->getAttribute('players');

        return ['team' => $team === null ? '' : $team->name, 'players' => is_numeric($players) ? (int) $players : 0];
    }

    public function paginateByRegistration(int $registrationId, ListQuery $query, ?string $search, ?RosterStatus $status): LengthAwarePaginator
    {
        $builder = $this->currentTeam($this->entity->newQuery(), $registrationId)
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->when($search !== null, function (Builder $builder) use ($search) {
                $builder->whereHas('player', fn (Builder $player) => $player->withTrashed()->whereHas('user', function (Builder $user) use ($search) {
                    $user->where(function (Builder $group) use ($search) {
                        $group->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhere('user_code', 'LIKE', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                    });
                }));
            })
            ->when(
                $query->sort === null,
                fn (Builder $builder) => $builder
                    ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                    ->orderBy('jersey_number'),
                fn (Builder $builder) => $builder->applySort($query->sort)
            )
            ->orderBy('id')
            ->with(['player' => fn ($player) => $player->withTrashed()->with('user'), 'requestedBy', 'removedBy']);

        return $query->paginate($builder);
    }

    public function countOpenByRegistration(int $registrationId): array
    {
        $count = fn (RosterStatus $status): int => $this->currentTeam($this->entity->newQuery(), $registrationId)
            ->where('status', $status)
            ->count();

        return ['active' => $count(RosterStatus::ACTIVE), 'pending' => $count(RosterStatus::PENDING)];
    }

    public function countByStatusOfRegistration(int $registrationId): array
    {
        $counts = $this->currentTeam($this->entity->newQuery(), $registrationId)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];

        foreach (RosterStatus::cases() as $status) {
            $total = $counts[$status->value] ?? 0;
            $byStatus[$status->value] = is_numeric($total) ? (int) $total : 0;
        }

        return $byStatus;
    }

    /**
     * Solo las filas del equipo actual de la inscripción: tras un reemplazo, las del equipo anterior quedan aparte.
     *
     * @param  Builder<Roster>  $builder
     * @return Builder<Roster>
     */
    private function currentTeam(Builder $builder, int $registrationId): Builder
    {
        return $builder
            ->where('registration_id', $registrationId)
            ->whereIn('team_id', Registration::query()->select('team_id')->whereKey($registrationId));
    }

    public function hasNewerRowOfPlayer(Roster $roster): bool
    {
        return $this->entity->newQuery()
            ->where('player_id', $roster->player_id)
            ->where('tournament_id', $roster->tournament_id)
            ->where('id', '>', $roster->id)
            ->exists();
    }

    public function revokeRejoin(Roster $roster): Roster
    {
        $roster->forceFill(['rejoin_authorized_at' => null]);
        $roster->saveOrFail();

        return $roster;
    }

    public function releasePlayerInLeague(int $playerId, int $leagueId, int $removedByUserId, string $reason): array
    {
        $openTournaments = fn ($query) => $query->select('id')
            ->where('league_id', $leagueId)
            ->whereIn('status', [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS]);

        $removed = $this->entity->newQuery()
            ->where('player_id', $playerId)
            ->where('status', RosterStatus::ACTIVE)
            ->whereIn('tournament_id', $openTournaments(DB::table('tournaments')))
            ->update([
                'status' => RosterStatus::REMOVED,
                'removed_by_user_id' => $removedByUserId,
                'removed_at' => now(),
                'removal_reason' => $reason,
            ]);

        $cancelled = $this->entity->newQuery()
            ->where('player_id', $playerId)
            ->where('status', RosterStatus::PENDING)
            ->whereIn('tournament_id', $openTournaments(DB::table('tournaments')))
            ->update(['status' => RosterStatus::CANCELLED, 'responded_at' => now()]);

        return ['removed' => $removed, 'cancelled' => $cancelled];
    }

    public function createRequest(int $registrationId, int $teamId, int $tournamentId, int $playerId, int $jerseyNumber, int $requestedByUserId): Roster
    {
        $roster = $this->entity->newInstance();
        $roster->forceFill([
            'registration_id' => $registrationId,
            'team_id' => $teamId,
            'tournament_id' => $tournamentId,
            'player_id' => $playerId,
            'jersey_number' => $jerseyNumber,
            'status' => RosterStatus::PENDING,
            'requested_by_user_id' => $requestedByUserId,
            'requested_at' => now(),
        ]);
        $roster->saveOrFail();

        return $roster;
    }

    public function accept(Roster $roster): Roster
    {
        return $this->respond($roster, RosterStatus::ACTIVE);
    }

    public function reject(Roster $roster): Roster
    {
        return $this->respond($roster, RosterStatus::REJECTED);
    }

    public function remove(Roster $roster, int $removedByUserId, string $reason): Roster
    {
        $roster->forceFill([
            'status' => RosterStatus::REMOVED,
            'removed_by_user_id' => $removedByUserId,
            'removed_at' => now(),
            'removal_reason' => $reason,
        ]);
        $roster->saveOrFail();

        return $roster;
    }

    public function authorizeRejoin(Roster $roster): Roster
    {
        $roster->forceFill(['rejoin_authorized_at' => now()]);
        $roster->saveOrFail();

        return $roster;
    }

    public function cancelPendingOfPlayer(int $playerId, int $tournamentId, int $exceptRosterId): int
    {
        return $this->entity->newQuery()
            ->where('player_id', $playerId)
            ->where('tournament_id', $tournamentId)
            ->where('status', RosterStatus::PENDING)
            ->where('id', '!=', $exceptRosterId)
            ->update(['status' => RosterStatus::CANCELLED, 'responded_at' => now()]);
    }

    public function releaseOpenByRegistration(int $registrationId): int
    {
        return $this->entity->newQuery()
            ->where('registration_id', $registrationId)
            ->whereIn('status', [RosterStatus::PENDING, RosterStatus::ACTIVE])
            ->update(['status' => RosterStatus::RELEASED, 'updated_at' => now()]);
    }

    public function cancelPendingOfTournament(int $tournamentId): int
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('status', RosterStatus::PENDING)
            ->update(['status' => RosterStatus::CANCELLED, 'responded_at' => now()]);
    }

    private function respond(Roster $roster, RosterStatus $status): Roster
    {
        $roster->forceFill(['status' => $status, 'responded_at' => now()]);
        $roster->saveOrFail();

        return $roster;
    }
}
