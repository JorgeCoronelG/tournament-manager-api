<?php

namespace App\Core;

use App\Core\Classes\Filter;
use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Contracts\BaseServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

/**
 * @template TModel of Model
 *
 * @implements BaseServiceInterface<TModel>
 */
class BaseService implements BaseServiceInterface
{
    /**
     * @param  BaseRepositoryInterface<TModel>  $entityRepository
     */
    public function __construct(protected BaseRepositoryInterface $entityRepository) {}

    /**
     * @return TModel
     */
    public function create(Data $data): Model
    {
        return $this->entityRepository->create($data->toArray());
    }

    public function delete(int|string $id): void
    {
        $this->entityRepository->delete($id);
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
        return $this->entityRepository->findAll($filter, $sort, $columns);
    }

    /**
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, TModel>
     */
    public function findAllPaginated(ListQuery $query, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->entityRepository->findAllPaginated(
            $query->filters,
            $query->perPage,
            $query->sort,
            $columns
        );
    }

    /**
     * @param  array<int, string>  $columns
     * @return TModel
     */
    public function findById(int|string $id, array $columns = ['*']): Model
    {
        return $this->entityRepository->findById($id, $columns);
    }

    /**
     * @return TModel
     */
    public function findRandom(): Model
    {
        return $this->entityRepository->findRandom();
    }

    /**
     * @return Collection<int, TModel>
     */
    public function findRandoms(int $records = 1): Collection
    {
        return $this->entityRepository->findRandoms($records);
    }

    /**
     * @return TModel
     */
    public function update(int|string $id, Data $data): Model
    {
        return $this->entityRepository->update($id, $data->toArray());
    }
}
