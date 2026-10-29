<?php

namespace App\Repositories;

use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Tournament>
 */
class TournamentRepository extends BaseRepository implements TournamentRepositoryInterface
{
    public function __construct(Tournament $entity)
    {
        parent::__construct($entity);
    }

    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?TournamentGender $gender,
        ?TournamentStatus $status
    ): LengthAwarePaginator {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->filter($query->filters)
            ->when($search !== null, fn (Builder $builder) => $builder->where('name', 'LIKE', "%{$search}%"))
            ->when($gender !== null, fn (Builder $builder) => $builder->where('gender', $gender))
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->applySort($query->sort)
            ->orderByDesc('id')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function countByLeagueExceptStatus(int $leagueId, TournamentStatus $status): int
    {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->where('status', '!=', $status)
            ->count();
    }

    public function createInLeague(int $leagueId, array $data): Tournament
    {
        $tournament = $this->entity->newInstance($data);
        $tournament->forceFill(['league_id' => $leagueId, 'status' => TournamentStatus::PLANNING]);
        $tournament->saveOrFail();

        return $tournament;
    }

    public function createRegularStageGroup(Tournament $tournament): TournamentGroup
    {
        $group = new TournamentGroup;
        $group->forceFill([
            'tournament_id' => $tournament->id,
            'name' => TournamentGroup::DEFAULT_NAME,
            'type' => TournamentGroupType::REGULAR_STAGE,
        ]);
        $group->saveOrFail();

        return $group;
    }

    public function updateModel(Tournament $tournament, array $data): Tournament
    {
        $tournament->fill($data);
        $tournament->saveOrFail();

        return $tournament;
    }

    public function updateStatus(Tournament $tournament, TournamentStatus $status): Tournament
    {
        $tournament->forceFill(['status' => $status]);
        $tournament->saveOrFail();

        return $tournament;
    }
}
