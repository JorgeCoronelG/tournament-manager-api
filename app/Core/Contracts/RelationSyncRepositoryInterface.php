<?php

namespace App\Core\Contracts;

interface RelationSyncRepositoryInterface
{
    /**
     * @param  array<int|string, mixed>  $attributes
     * @return array<string, array<int, int|string>>
     */
    public function sync(int|string $id, string $relation, array $attributes, bool $detaching = true): array;
}
