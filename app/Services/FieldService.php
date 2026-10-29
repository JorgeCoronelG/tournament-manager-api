<?php

namespace App\Services;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Contracts\Services\FieldServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\FieldStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Data\Fields\FieldData;
use App\Exceptions\CustomErrorException;
use App\Models\Field;
use App\Models\League;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

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
        if (
            $status === FieldStatus::INACTIVE
            && $field->status !== FieldStatus::INACTIVE
            && $this->fieldRepository->hasMatchesWithStatus($field->id, MatchStatus::SCHEDULED)
        ) {
            throw new CustomErrorException(Message::getMessageFieldDeactivateHasScheduledMatches($field->name), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->fieldRepository->updateStatus($field, $status);
    }

    public function deleteField(Field $field): void
    {
        if ($this->fieldRepository->hasMatchesWithStatus($field->id, MatchStatus::SCHEDULED)) {
            throw new CustomErrorException(Message::getMessageFieldHasScheduledMatches($field->name), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->fieldRepository->isInScheduleConfigExceptStatus($field->id, TournamentStatus::FINISHED)) {
            throw new CustomErrorException(Message::getMessageFieldInOpenScheduleConfig($field->name), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->delete($field->id);
    }
}
