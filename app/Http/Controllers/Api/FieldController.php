<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\FieldServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\FieldStatus;
use App\Http\Requests\Fields\SaveFieldRequest;
use App\Http\Requests\Fields\UpdateFieldStatusRequest;
use App\Http\Resources\Fields\FieldResource;
use App\Models\Field;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FieldController extends BaseApiController
{
    public function __construct(protected FieldServiceInterface $fieldService) {}

    public function index(Request $request, League $league): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= 'name';

        $fields = $this->fieldService->paginateByLeague(
            $league,
            $listQuery,
            $this->parseSearch($request),
            $this->parseEnum($request, 'status', FieldStatus::class)
        );

        return $this->showAll(FieldResource::collection($fields));
    }

    public function store(SaveFieldRequest $request, League $league): JsonResponse
    {
        $field = $this->fieldService->createInLeague($league, $request->toData());

        return $this->showOne(new FieldResource($field), Response::HTTP_CREATED);
    }

    public function show(League $league, Field $field): JsonResponse
    {
        return $this->showOne(new FieldResource($field));
    }

    public function update(SaveFieldRequest $request, League $league, Field $field): JsonResponse
    {
        return $this->showOne(new FieldResource($this->fieldService->updateField($field, $request->toData())));
    }

    public function updateStatus(UpdateFieldStatusRequest $request, League $league, Field $field): JsonResponse
    {
        $status = FieldStatus::from($request->string('status')->toString());

        return $this->showOne(new FieldResource($this->fieldService->changeStatus($field, $status)));
    }

    public function destroy(League $league, Field $field): Response
    {
        $this->fieldService->deleteField($field);

        return $this->noContentResponse();
    }
}
