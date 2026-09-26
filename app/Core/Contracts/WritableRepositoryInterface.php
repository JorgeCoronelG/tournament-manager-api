<?php

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
interface WritableRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function create(array $data): Model;

    /**
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function update(int|string $id, array $data): Model;

    public function delete(int|string $id): void;
}
