<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\ScheduleGeneratorServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Schedule\GenerateScheduleRequest;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ScheduleController extends BaseApiController
{
    public function __construct(
        protected ScheduleGeneratorServiceInterface $generator,
        protected PlayoffScheduleServiceInterface $playoffGenerator,
    ) {}

    public function preview(GenerateScheduleRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        if ($request->phase() === 'playoffs') {
            return $this->successResponse($this->playoffGenerator->preview($tournament, $request->scope(), $request->alignFinals()), Response::HTTP_OK);
        }

        return $this->successResponse($this->generator->preview($tournament, $request->mode(), $request->scope(), $request->seed()), Response::HTTP_OK);
    }

    public function generate(GenerateScheduleRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        if ($request->phase() === 'playoffs') {
            return $this->successResponse($this->playoffGenerator->generate($tournament, $request->scope(), $request->alignFinals()), Response::HTTP_OK);
        }

        return $this->successResponse($this->generator->generate($tournament, $request->mode(), $request->scope(), $request->seed()), Response::HTTP_OK);
    }

    public function destroy(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        $request->validate(['phase' => ['nullable', Rule::in(['regular', 'playoffs'])]], [], ['phase' => 'fase']);

        $cleared = $request->query('phase') === 'playoffs' ? $this->playoffGenerator->clear($tournament) : $this->generator->clear($tournament);

        return $this->successResponse($cleared, Response::HTTP_OK);
    }
}
