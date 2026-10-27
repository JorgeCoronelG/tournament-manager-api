<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\MatchScheduleServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Matches\SwapMatchScheduleRequest;
use App\Http\Requests\Matches\UpdateMatchScheduleRequest;
use App\Http\Resources\Matches\MatchResource;
use App\Models\GameMatch;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Horario y árbitro de un partido. Va fuera de /tournaments/{tournament}: el servicio comprueba que el partido sea
 * de la liga de la ruta (si no, 404).
 */
class MatchScheduleController extends BaseApiController
{
    public function __construct(protected MatchScheduleServiceInterface $schedule) {}

    public function update(UpdateMatchScheduleRequest $request, League $league, GameMatch $match): JsonResponse
    {
        $result = $this->schedule->update($league, $match, $request->changes());

        return $this->successResponse((new MatchResource($result['match']))->resolve() + ['warnings' => $result['warnings']], Response::HTTP_OK);
    }

    public function swap(SwapMatchScheduleRequest $request, League $league, GameMatch $match): JsonResponse
    {
        $result = $this->schedule->swap($league, $match, $request->withMatchId());

        return $this->successResponse([
            'match' => (new MatchResource($result['match']))->resolve(),
            'with_match' => (new MatchResource($result['with_match']))->resolve(),
            'warnings' => $result['warnings'],
        ], Response::HTTP_OK);
    }

    public function availableSlots(Request $request, League $league, GameMatch $match): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']], [], ['date' => 'fecha']);

        return $this->successResponse($this->schedule->availableSlots($league, $match, $request->filled('date') ? $request->string('date')->value() : null), Response::HTTP_OK);
    }

    public function availableReferees(Request $request, League $league, GameMatch $match): JsonResponse
    {
        $request->validate(['match_datetime' => ['nullable', 'date']], [], ['match_datetime' => 'fecha y hora']);

        return $this->successResponse(
            ['referees' => $this->schedule->availableReferees($league, $match, $request->filled('match_datetime') ? $request->string('match_datetime')->value() : null)],
            Response::HTTP_OK
        );
    }
}
