<?php

namespace Tests\Support;

use App\Core\Classes\ListQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * No se ejecuta: lo analiza Larastan (ver phpstan.neon). Comprueba que los genéricos
 * de BaseRepository y BaseService devuelven el modelo concreto (Item) y no Model.
 * Si alguien rompe los @template, este archivo deja de pasar el análisis estático.
 */
final class GenericsTypeCheck
{
    public function __construct(
        private readonly ItemRepository $repository,
        private readonly ItemService $service,
    ) {}

    public function repositoryFindById(): Item
    {
        return $this->repository->findById(1);
    }

    public function repositoryFindRandom(): Item
    {
        return $this->repository->findRandom();
    }

    public function repositoryCreate(): Item
    {
        return $this->repository->create(['name' => 'Ana']);
    }

    public function repositoryUpdate(): Item
    {
        return $this->repository->update(1, ['name' => 'Ana']);
    }

    /**
     * @return Collection<int, Item>
     */
    public function repositoryFindAll(): Collection
    {
        return $this->repository->findAll();
    }

    /**
     * @return Collection<int, Item>
     */
    public function repositoryFindRandoms(): Collection
    {
        return $this->repository->findRandoms(2);
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    public function repositoryFindAllPaginated(): LengthAwarePaginator
    {
        return $this->repository->findAllPaginated([], 10);
    }

    public function serviceFindById(): Item
    {
        return $this->service->findById(1);
    }

    public function serviceFindRandom(): Item
    {
        return $this->service->findRandom();
    }

    public function serviceCreate(ItemData $data): Item
    {
        return $this->service->create($data);
    }

    public function serviceUpdate(ItemData $data): Item
    {
        return $this->service->update(1, $data);
    }

    /**
     * @return Collection<int, Item>
     */
    public function serviceFindAll(): Collection
    {
        return $this->service->findAll();
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    public function serviceFindAllPaginated(): LengthAwarePaginator
    {
        return $this->service->findAllPaginated(new ListQuery);
    }
}
