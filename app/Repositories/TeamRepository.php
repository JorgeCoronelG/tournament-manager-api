<?php

namespace App\Repositories;

use App\Contracts\Repositories\TeamRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\TeamStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Team>
 */
class TeamRepository extends BaseRepository implements TeamRepositoryInterface
{
    public function __construct(Team $entity)
    {
        parent::__construct($entity);
    }

    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?TeamStatus $status,
        ?bool $hasManager,
        ?int $managerUserId = null
    ): LengthAwarePaginator {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->filter($query->filters)
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->when($hasManager !== null, function (Builder $builder) use ($hasManager) {
                $hasManager ? $builder->whereNotNull('manager_user_id') : $builder->whereNull('manager_user_id');
            })
            ->when($managerUserId !== null, fn (Builder $builder) => $builder->where('manager_user_id', $managerUserId))
            ->when($search !== null, function (Builder $builder) use ($search) {
                $builder->where(function (Builder $group) use ($search) {
                    $group->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('manager', function (Builder $manager) use ($search) {
                            $manager->where(function (Builder $user) use ($search) {
                                $user->where('first_name', 'LIKE', "%{$search}%")
                                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%")
                                    ->orWhere('user_code', 'LIKE', "%{$search}%")
                                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                            });
                        });
                });
            })
            ->applySort($query->sort)
            ->orderBy('id')
            ->with('manager')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function managersByLeague(int $leagueId): Collection
    {
        return User::query()
            ->whereIn('id', $this->entity->newQuery()->where('league_id', $leagueId)->whereNotNull('manager_user_id')->select('manager_user_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    public function createInLeague(int $leagueId, array $data, ?int $managerUserId): Team
    {
        $team = $this->entity->newInstance($data);
        $team->forceFill([
            'league_id' => $leagueId,
            'manager_user_id' => $managerUserId,
            'status' => TeamStatus::ACTIVE,
        ]);
        $team->saveOrFail();

        return $team;
    }

    public function updateModel(Team $team, array $data): Team
    {
        $team->fill($data);
        $team->saveOrFail();

        return $team;
    }

    public function existsWithNameAndManager(int $leagueId, string $name, ?int $managerUserId, ?int $exceptTeamId = null): bool
    {
        $wanted = $this->normalizeName($name);

        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->when(
                $managerUserId === null,
                fn (Builder $builder) => $builder->whereNull('manager_user_id'),
                fn (Builder $builder) => $builder->where('manager_user_id', $managerUserId)
            )
            ->when($exceptTeamId !== null, fn (Builder $builder) => $builder->where('id', '!=', $exceptTeamId))
            ->lockForUpdate()
            ->pluck('name')
            ->contains(fn (string $existing) => $this->normalizeName($existing) === $wanted);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public function updateStatus(Team $team, TeamStatus $status): Team
    {
        $team->forceFill(['status' => $status]);
        $team->saveOrFail();

        return $team;
    }

    public function setManager(Team $team, ?int $managerUserId): Team
    {
        $team->forceFill(['manager_user_id' => $managerUserId]);
        $team->saveOrFail();
        $team->unsetRelation('manager');

        return $team;
    }

    public function setLogoPath(Team $team, ?string $logoPath): Team
    {
        $team->forceFill(['logo_path' => $logoPath]);
        $team->saveOrFail();

        return $team;
    }

    public function countManagedInOtherLeagues(int $userId, int $exceptLeagueId): int
    {
        return $this->entity->newQuery()
            ->where('manager_user_id', $userId)
            ->where('league_id', '!=', $exceptLeagueId)
            ->count();
    }

    public function countByManager(int $userId): int
    {
        return $this->entity->newQuery()->where('manager_user_id', $userId)->count();
    }
}
