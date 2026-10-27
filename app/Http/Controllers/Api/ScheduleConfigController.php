<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\ScheduleServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Schedule\SaveScheduleConfigRequest;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ScheduleConfigController extends BaseApiController
{
    public function __construct(
        protected ScheduleServiceInterface $scheduleService,
        protected PlayoffScheduleServiceInterface $playoffSchedule,
    ) {}

    public function show(League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->scheduleService->show($tournament), Response::HTTP_OK);
    }

    public function update(SaveScheduleConfigRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->scheduleService->save($tournament, $request->config()), Response::HTTP_OK);
    }

    public function capacity(League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->scheduleService->capacity($tournament) + $this->playoffSchedule->capacity($tournament), Response::HTTP_OK);
    }

    public function previewCapacity(SaveScheduleConfigRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->scheduleService->previewCapacity($tournament, $request->config()), Response::HTTP_OK);
    }
}
