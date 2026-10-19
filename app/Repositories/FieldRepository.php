<?php

namespace App\Repositories;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\FieldStatus;
use App\Models\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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
