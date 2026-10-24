<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayoffServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Playoffs\BestManualOrderRequest;
use App\Http\Requests\Playoffs\ClosePhaseRequest;
use App\Http\Resources\Tournaments\TournamentResource;
use App\Models\League;
use App\Models\PlayoffSource;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PlayoffController extends BaseApiController
{
    public function __construct(protected PlayoffServiceInterface $playoffService) {}

    public function preview(League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->playoffService->preview($tournament), Response::HTTP_OK);
    }

    public function close(ClosePhaseRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->showOne(new TournamentResource($this->playoffService->close($tournament, $request->entries())));
    }

    public function saveBestManualOrder(BestManualOrderRequest $request, League $league, Tournament $tournament, PlayoffSource $playoffSource): JsonResponse
    {
        $this->playoffService->saveBestManualOrder($tournament, $playoffSource, $request->teamIds());

        return $this->successResponse($this->playoffService->preview($tournament), Response::HTTP_OK);
    }

    public function clearBestManualOrder(League $league, Tournament $tournament, PlayoffSource $playoffSource): Response
    {
        $this->playoffService->clearBestManualOrder($tournament, $playoffSource);

        return $this->noContentResponse();
    }

    public function show(League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->playoffService->bracket($tournament), Response::HTTP_OK);
    }
}
