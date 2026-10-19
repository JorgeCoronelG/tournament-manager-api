<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\FieldStatus;
use App\Models\Field;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Field>
 */
interface FieldRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Canchas no eliminadas de una liga. `search` filtra por nombre.
     *
     * @return LengthAwarePaginator<int, Field>
     */
    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?FieldStatus $status
    ): LengthAwarePaginator;

    /**
     * Crea la cancha en la liga indicada, con el estado indicado (activa por defecto).
     *
     * @param  array<string, mixed>  $data
     */
    public function createInLeague(int $leagueId, array $data, FieldStatus $status = FieldStatus::ACTIVE): Field;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateModel(Field $field, array $data): Field;

    public function updateStatus(Field $field, FieldStatus $status): Field;
}
