<?php

namespace App\Repositories;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\FieldStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<Field>
 */
class FieldRepository extends BaseRepository implements FieldRepositoryInterface
{
    public function __construct(Field $entity)
    {
        parent::__construct($entity);
    }

    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?FieldStatus $status
    ): LengthAwarePaginator {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->filter($query->filters)
            ->when($search !== null, fn (Builder $builder) => $builder->where('name', 'LIKE', "%{$search}%"))
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->applySort($query->sort)
            ->orderByDesc('id')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function createInLeague(int $leagueId, array $data, FieldStatus $status = FieldStatus::ACTIVE): Field
    {
        $field = $this->entity->newInstance($data);
        $field->forceFill(['league_id' => $leagueId, 'status' => $status]);
        $field->saveOrFail();

        return $field;
    }

    public function hasMatchesWithStatus(int $fieldId, MatchStatus $status): bool
    {
        return GameMatch::query()->where('field_id', $fieldId)->where('status', $status)->exists();
    }

    public function isInScheduleConfigExceptStatus(int $fieldId, TournamentStatus $status): bool
    {
        $open = fn (string $table) => DB::table($table)
            ->join('tournaments', 'tournaments.id', '=', "{$table}.tournament_id")
            ->where("{$table}.field_id", $fieldId)
            ->whereNull('tournaments.deleted_at')
            ->where('tournaments.status', '!=', $status->value);

        return $open('tournament_schedule_windows')->exists() || $open('tournament_schedule_blocks')->exists();
    }

    public function deleteByLeague(int $leagueId): void
    {
        $this->entity->newQuery()->where('league_id', $leagueId)->get()->each->delete();
    }

    public function updateModel(Field $field, array $data): Field
    {
        $field->fill($data);
        $field->saveOrFail();

        return $field;
    }

    public function updateStatus(Field $field, FieldStatus $status): Field
    {
        $field->forceFill(['status' => $status]);
        $field->saveOrFail();

        return $field;
    }
}
