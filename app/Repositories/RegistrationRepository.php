<?php

namespace App\Repositories;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Registration>
 */
class RegistrationRepository extends BaseRepository implements RegistrationRepositoryInterface
{
    public function __construct(Registration $entity)
    {
        parent::__construct($entity);
    }

    public function paginateByTournament(int $tournamentId, ListQuery $query, ?string $search, ?RegistrationStatus $status): LengthAwarePaginator
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->filter($query->filters)
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->when($search !== null, function (Builder $builder) use ($search) {
                $builder->whereHas('team', fn (Builder $team) => $this->matchTeamOrManager($team, (string) $search));
            })
            ->applySort($query->sort)
            ->orderByDesc('id')
            ->with('team.manager')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function paginateAvailableTeams(int $leagueId, int $tournamentId, ListQuery $query, ?string $search): LengthAwarePaginator
    {
        return Team::query()
            ->where('league_id', $leagueId)
            ->whereDoesntHave('registrations', fn (Builder $registration) => $registration->where('tournament_id', $tournamentId))
            ->when($search !== null, fn (Builder $builder) => $this->matchTeamOrManager($builder, (string) $search))
            ->applySort($query->sort)
            ->orderBy('id')
            ->with('manager')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function lockTournament(int $tournamentId): Tournament
    {
        return Tournament::query()->lockForUpdate()->findOrFail($tournamentId);
    }

    public function findTeamsInLeague(int $leagueId, array $teamIds): Collection
    {
        return Team::query()
            ->where('league_id', $leagueId)
            ->whereIn('id', $teamIds)
            ->get()
            ->keyBy('id');
    }

    public function registeredTeamIds(int $tournamentId, array $teamIds): array
    {
        $ids = $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_id', $teamIds)
            ->pluck('team_id')
            ->map(fn (mixed $id) => (int) (is_numeric($id) ? $id : 0))
            ->all();

        return array_values($ids);
    }

    public function activeTeamNames(int $tournamentId): array
    {
        $names = Team::query()
            ->whereIn('id', $this->entity->newQuery()
                ->where('tournament_id', $tournamentId)
                ->where('status', RegistrationStatus::ACTIVE)
                ->select('team_id'))
            ->pluck('name')
            ->map(fn (mixed $name) => (string) (is_scalar($name) ? $name : ''))
            ->all();

        return array_values($names);
    }

    public function register(int $tournamentId, int $teamId): Registration
    {
        $registration = $this->entity->newQuery()->withTrashed()
            ->where('tournament_id', $tournamentId)
            ->where('team_id', $teamId)
            ->first() ?? $this->entity->newInstance();

        $registration->forceFill([
            'tournament_id' => $tournamentId,
            'team_id' => $teamId,
            'status' => RegistrationStatus::ACTIVE,
            'deleted_at' => null,
        ]);
        $registration->saveOrFail();

        return $registration;
    }

    public function countActiveByTournament(int $tournamentId): int
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->count();
    }

    public function countBannedTeamsByTournament(int $tournamentId): int
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereHas('team', fn (Builder $team) => $team->where('status', TeamStatus::BANNED))
            ->count();
    }

    public function teamHasActiveRegistrations(int $teamId): bool
    {
        return $this->entity->newQuery()
            ->where('team_id', $teamId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->exists();
    }

    public function deleteByTournament(int $tournamentId): void
    {
        $this->entity->newQuery()->where('tournament_id', $tournamentId)->delete();
    }

    /**
     * @param  Builder<Team>  $teams
     */
    private function matchTeamOrManager(Builder $teams, string $search): void
    {
        $teams->where(function (Builder $group) use ($search) {
            $group->where('name', 'LIKE', "%{$search}%")
                ->orWhereHas('manager', function (Builder $manager) use ($search) {
                    $manager->where(function (Builder $user) use ($search) {
                        $user->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                    });
                });
        });
    }
}
