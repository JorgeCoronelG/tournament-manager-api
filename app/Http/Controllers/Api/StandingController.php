<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\StandingServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Standings\ManualOrderRequest;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StandingController extends BaseApiController
{
    public function __construct(protected StandingServiceInterface $standingService) {}

    public function index(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        $groupId = $request->filled('group_id') ? $request->integer('group_id') : null;

        return $this->successResponse(['groups' => $this->standingService->standings($tournament, $groupId)], Response::HTTP_OK);
    }

    public function saveManualOrder(ManualOrderRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $groupId = $request->integer('group_id');
        $this->standingService->saveManualOrder($tournament, $groupId, $request->registrationIds());

        return $this->successResponse(['groups' => $this->standingService->standings($tournament, $groupId)], Response::HTTP_OK);
    }

    public function clearManualOrder(Request $request, League $league, Tournament $tournament): Response
    {
        $this->standingService->clearManualOrder($tournament, $request->integer('group_id'));

        return $this->noContentResponse();
    }
}
