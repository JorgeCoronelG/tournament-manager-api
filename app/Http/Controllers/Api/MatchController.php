<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\MatchServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Http\Requests\Matches\GenerateMatchesRequest;
use App\Http\Requests\Matches\MatchResultRequest;
use App\Http\Resources\Matches\MatchResource;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MatchController extends BaseApiController
{
    public function __construct(protected MatchServiceInterface $matchService) {}

    public function index(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        $filters = array_filter([
            'group_id' => $request->filled('group_id') ? $request->integer('group_id') : null,
            'matchday' => $request->filled('matchday') ? $request->integer('matchday') : null,
            'round_number' => $request->filled('round_number') ? $request->integer('round_number') : null,
            'status' => $this->parseEnum($request, 'status', MatchStatus::class)?->value,
            'phase' => $this->parseEnum($request, 'phase', MatchPhase::class)?->value,
        ], fn (mixed $value) => $value !== null);

        $matches = $this->matchService->paginateByTournament($tournament, ListQuery::fromRequest($request), $filters);

        return $this->showAll(MatchResource::collection($matches));
    }

    public function schedule(League $league, Tournament $tournament): JsonResponse
    {
        $schedule = $this->matchService->schedule($tournament);

        return $this->successResponse([
            'generated' => $schedule['generated'],
            'groups' => array_map(fn (array $group) => [
                'group_id' => $group['group_id'],
                'group_name' => $group['group_name'],
                'matchdays' => array_map(fn (array $matchday) => [
                    'matchday' => $matchday['matchday'],
                    'round_number' => $matchday['round_number'],
                    'matches' => array_map(fn (GameMatch $match) => (new MatchResource($match))->resolve(), $matchday['matches']),
                    'resting' => $matchday['resting'],
                ], $group['matchdays']),
            ], $schedule['groups']),
        ], Response::HTTP_OK);
    }

    public function generate(GenerateMatchesRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->matchService->generate($tournament, $request->shuffle()), Response::HTTP_OK);
    }

    public function updateResult(MatchResultRequest $request, League $league, Tournament $tournament, GameMatch $match): JsonResponse
    {
        $result = $this->matchService->recordResult(
            $tournament,
            $match,
            $request->homeGoals(),
            $request->awayGoals(),
            $request->homePenalties(),
            $request->awayPenalties()
        );

        return $this->resultResponse($result);
    }

    public function destroyResult(League $league, Tournament $tournament, GameMatch $match): JsonResponse
    {
        return $this->resultResponse($this->matchService->clearResult($tournament, $match));
    }

    public function destroy(League $league, Tournament $tournament): Response
    {
        $this->matchService->deleteAll($tournament);

        return $this->noContentResponse();
    }

    /**
     * El partido (como siempre) más, en liguilla, el cruce actualizado y el avance del ganador (`null` en fase regular).
     *
     * @param  array{match: GameMatch, tie: array<string, mixed>|null, advanced_to: array<string, mixed>|null}  $result
     */
    private function resultResponse(array $result): JsonResponse
    {
        $match = $result['match']->load(['group', 'field', 'home.team', 'away.team']);

        return $this->successResponse(
            (new MatchResource($match))->resolve() + ['tie' => $result['tie'], 'advanced_to' => $result['advanced_to']],
            Response::HTTP_OK
        );
    }
}
