<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\PlayoffServiceInterface;
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
        protected PlayoffServiceInterface $playoffService,
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
        return $this->successResponse($this->scheduleService->capacity($tournament) + $this->playoffSchedule->capacity($tournament) + ['playoff_min_start_date' => $this->playoffSchedule->minStartDate($tournament)], Response::HTTP_OK);
    }

    public function previewCapacity(SaveScheduleConfigRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $config = $request->config();
        $result = $this->scheduleService->previewCapacity($tournament, $config) + ['playoff_min_start_date' => $this->playoffSchedule->minStartDate($tournament)];
        $dates = $request->playoffDates();

        if ($dates !== null) {
            $result += $this->playoffSchedule->capacityWithDates($tournament, $config, $dates, $this->playoffService->proposalPlayoffs($tournament));
        } else {
            // Liguilla ya creada: sus fechas están en los partidos; se calcula como el GET pero con la configuración sin guardar.
            $playoff = $this->playoffSchedule->capacity($tournament, $config);

            if ($playoff['playoff_rounds'] !== []) {
                $result += $playoff;
            }
        }

        return $this->successResponse($result, Response::HTTP_OK);
    }
}
