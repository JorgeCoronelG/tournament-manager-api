<?php

namespace App\Repositories;

use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Enum\RosterStatus;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
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
        return $this->entity->newQuery()
            ->where('registration_id', $registrationId)
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

    public function createRequest(int $registrationId, int $tournamentId, int $playerId, int $jerseyNumber, int $requestedByUserId): Roster
    {
        $roster = $this->entity->newInstance();
        $roster->forceFill([
            'registration_id' => $registrationId,
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
