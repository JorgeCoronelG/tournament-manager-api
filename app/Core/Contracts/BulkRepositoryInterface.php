<?php

namespace App\Core\Contracts;

/**
 * Operaciones masivas. No disparan eventos de Eloquent y bulkInsert no rellena timestamps.
 */
interface BulkRepositoryInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    public function bulkInsert(array $data): bool;

    /**
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $data
     */
    public function bulkUpdate(array $ids, array $data, string $primaryKey = 'id'): int;

    /**
     * @param  array<int, int|string>  $ids
     */
    public function bulkDelete(array $ids, string $primaryKey = 'id'): int;
}
