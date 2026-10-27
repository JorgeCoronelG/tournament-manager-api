<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\AgendaServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Schedule\AgendaRequest;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AgendaController extends BaseApiController
{
    public function __construct(protected AgendaServiceInterface $agenda) {}

    public function index(AgendaRequest $request, League $league): JsonResponse
    {
        return $this->successResponse($this->agenda->agenda($league, $request->filters()), Response::HTTP_OK);
    }

    public function unscheduled(AgendaRequest $request, League $league): JsonResponse
    {
        return $this->successResponse($this->agenda->unscheduled($league, $request->tournamentId()), Response::HTTP_OK);
    }
}
