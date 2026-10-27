<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\ScheduleGeneratorServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Schedule\GenerateScheduleRequest;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ScheduleController extends BaseApiController
{
    public function __construct(protected ScheduleGeneratorServiceInterface $generator) {}

    public function preview(GenerateScheduleRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->generator->preview($tournament, $request->mode(), $request->scope(), $request->seed()), Response::HTTP_OK);
    }

    public function generate(GenerateScheduleRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->generator->generate($tournament, $request->mode(), $request->scope(), $request->seed()), Response::HTTP_OK);
    }

    public function destroy(League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->generator->clear($tournament), Response::HTTP_OK);
    }
}
