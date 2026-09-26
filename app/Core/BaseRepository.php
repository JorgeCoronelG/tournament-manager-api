<?php

namespace App\Core;

use App\Core\Classes\Filter;
use App\Core\Contracts\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @template TModel of Model
 *
 * @implements BaseRepositoryInterface<TModel>
 */
class BaseRepository implements BaseRepositoryInterface
{
    /**
     * @param  TModel  $entity
     */
    public function __construct(protected Model $entity) {}

    /**
     * @param  array<string, mixed>  $data
     * @return TModel
     *
     * @throws \Throwable
     */
    public function create(array $data): Model
    {
        $entity = $this->entity->newInstance($data);
        $entity->saveOrFail();

        return $entity;
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    public function bulkInsert(array $data): bool
    {
        return $this->entity->insert($data);
    }

    public function delete(int|string $id): void
    {
        $entity = $this->findById($id);
        $entity->delete();
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function bulkDelete(array $ids, string $primaryKey = 'id'): int
    {
        /** @var int $deleted Filas eliminadas (o marcadas, con SoftDeletes) */
        $deleted = $this->entity
            ->whereIn($primaryKey, $ids)
            ->delete();

        return $deleted;
    }

    /**
     * @param  array<int, Filter>  $filter
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    public function findAll(
        array $filter = [],
        ?string $sort = null,
        array $columns = ['*']
    ): Collection {
        return $this->listQuery($filter, $sort)->get($columns);
    }

    /**
     * @param  array<int, Filter>  $filters
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, TModel>
     */
    public function findAllPaginated(
        array $filters,
        int $limit,
        ?string $sort = null,
        array $columns = ['*']
    ): LengthAwarePaginator {
        // withQueryString: los enlaces de siguiente/anterior conservan filtros, orden y tamaño de página.
        return $this->listQuery($filters, $sort)->paginate($limit, $columns)->withQueryString();
    }

    /**
     * @param  array<int, string>  $columns
     * @return TModel
     */
    public function findById(int|string $id, array $columns = ['*']): Model
    {
        return $this->entity->newQuery()->findOrFail($id, $columns);
    }

    /**
     * @return TModel
     */
    public function findRandom(): Model
    {
        return $this->entity->newQuery()
            ->inRandomOrder()
            ->limit(1)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, TModel>
     */
    public function findRandoms(int $records = 1): Collection
    {
        return $this->entity->newQuery()
            ->inRandomOrder()
            ->limit($records)
            ->get();
    }

    /**
     * @param  array<int|string, mixed>  $attributes
     * @return array<string, array<int, int|string>>
     */
    public function sync(int|string $id, string $relation, array $attributes, bool $detaching = true): array
    {
        return $this->findById($id)
            ->{$relation}()
            ->sync($attributes, $detaching);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TModel
     *
     * @throws \Throwable
     */
    public function update(int|string $id, array $data): Model
    {
        $entity = $this->findById($id);
        $entity->fill($data);
        $entity->saveOrFail();

        return $entity;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $data
     */
    public function bulkUpdate(array $ids, array $data, string $primaryKey = 'id'): int
    {
        return $this->entity
            ->whereIn($primaryKey, $ids)
            ->update($data);
    }

    /**
     * Consulta con los scopes de filtro y orden (traits AdvancedFilter y Sortable del modelo).
     *
     * @param  array<int, Filter>  $filters
     * @return Builder<TModel>
     */
    private function listQuery(array $filters, ?string $sort): Builder
    {
        /** @var Builder<TModel> */
        return $this->entity->newQuery()->filter($filters)->applySort($sort);
    }
}
