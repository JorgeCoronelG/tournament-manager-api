<?php

namespace App\Services;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Contracts\Services\FieldServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\FieldStatus;
use App\Data\Fields\FieldData;
use App\Models\Field;
use App\Models\League;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseService<Field>
 */
class FieldService extends BaseService implements FieldServiceInterface
{
    public function __construct(protected FieldRepositoryInterface $fieldRepository)
    {
        parent::__construct($fieldRepository);
    }

    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?FieldStatus $status
    ): LengthAwarePaginator {
        return $this->fieldRepository->paginateByLeague($league->id, $query, $search, $status);
    }

    public function createInLeague(League $league, FieldData $data): Field
    {
        return $this->fieldRepository->createInLeague($league->id, ['name' => $data->name], $data->status ?? FieldStatus::ACTIVE);
    }

    public function updateField(Field $field, FieldData $data): Field
    {
        return $this->fieldRepository->updateModel($field, ['name' => $data->name]);
    }

    public function changeStatus(Field $field, FieldStatus $status): Field
    {
        return $this->fieldRepository->updateStatus($field, $status);
    }

    public function deleteField(Field $field): void
    {
        // TODO: cuando exista matches, rechazar con 422 si la cancha tiene partidos programados
        // («Tiene partidos programados; desactívala en su lugar»).
        $this->delete($field->id);
    }
}
