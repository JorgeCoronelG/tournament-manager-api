<?php

namespace App\Repositories;

use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
        array $statuses = [],
        array $excludeStatuses = []
    ): LengthAwarePaginator {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->filter($query->filters)
            ->when($search !== null, fn (Builder $builder) => $builder->where('name', 'LIKE', "%{$search}%"))
            ->when($gender !== null, fn (Builder $builder) => $builder->where('gender', $gender))
            ->when($statuses !== [], fn (Builder $builder) => $builder->whereIn('status', $statuses))
            ->when($excludeStatuses !== [], fn (Builder $builder) => $builder->whereNotIn('status', $excludeStatuses))
            ->applySort($query->sort)
            ->orderByDesc('id')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function paginateHistory(int $leagueId, ?string $search, ?int $year, int $perPage): LengthAwarePaginator
    {
        $endDate = DB::table('matches')
            ->selectRaw('MAX(COALESCE(DATE(match_datetime), scheduled_date))')
            ->whereColumn('matches.tournament_id', 'tournaments.id')
            ->where('matches.status', '!=', 'cancelled')
            ->whereNull('matches.deleted_at');

        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->where('status', TournamentStatus::FINISHED)
            ->when($search !== null, fn (Builder $builder) => $builder->where('name', 'LIKE', "%{$search}%"))
            ->when($year !== null, fn (Builder $builder) => $builder->whereYear('start_date', $year))
            ->addSelect(['tournaments.*', 'end_date' => $endDate])
            ->withCount([
                'registrations as teams_count' => fn (Builder $builder) => $builder->where('status', RegistrationStatus::ACTIVE),
                'groups as playoffs_count' => fn (Builder $builder) => $builder->where('type', TournamentGroupType::PLAYOFF),
            ])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function historyYears(int $leagueId): array
    {
        $years = [];

        foreach ($this->entity->newQuery()->where('league_id', $leagueId)->where('status', TournamentStatus::FINISHED)->get(['id', 'start_date']) as $tournament) {
            $years[(int) $tournament->start_date->format('Y')] = true;
        }

        $years = array_keys($years);
        rsort($years);

        return $years;
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
