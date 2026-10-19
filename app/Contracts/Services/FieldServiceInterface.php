<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\FieldStatus;
use App\Data\Fields\FieldData;
use App\Models\Field;
use App\Models\League;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<Field>
 */
interface FieldServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Field>
     */
    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?FieldStatus $status
    ): LengthAwarePaginator;

    public function createInLeague(League $league, FieldData $data): Field;

    /**
     * Solo cambia el nombre; el estado tiene su propio método.
     */
    public function updateField(Field $field, FieldData $data): Field;

    public function changeStatus(Field $field, FieldStatus $status): Field;

    /**
     * Baja lógica.
     */
    public function deleteField(Field $field): void;
}
