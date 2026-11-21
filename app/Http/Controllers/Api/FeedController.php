<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\FeedServiceInterface;
use App\Core\BaseApiController;
use App\Http\Resources\Feed\FeedMatchResource;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App móvil, todos los roles: Inicio (partidos de hoy, próximos y resultados), detalle de un partido y Tabla.
 * Filtros de Inicio: `tournament_id` y `mine=1` (solo los partidos de los equipos del usuario).
 */
class FeedController extends BaseApiController
{
    public function __construct(protected FeedServiceInterface $feedService) {}

    public function home(Request $request): JsonResponse
    {
        $home = $this->feedService->home($this->actor($request), $this->parseId($request, 'tournament_id'), $this->parseBool($request, 'mine') ?? false);

        return $this->successResponse([
            'today' => FeedMatchResource::collection($home['today']),
            'upcoming' => FeedMatchResource::collection($home['upcoming']),
            'results' => $this->pageResponse($home['results']),
            'tournaments' => $home['tournaments']->map(fn (Tournament $tournament) => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status->value,
                'league_name' => $tournament->league->name ?? '',
            ])->values(),
            'my_registration_ids' => $home['my_registration_ids'],
        ], Response::HTTP_OK);
    }

    public function results(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');
        $offset = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : 0;

        return $this->successResponse($this->pageResponse($this->feedService->results(
            $this->actor($request),
            $this->parseId($request, 'tournament_id'),
            $this->parseBool($request, 'mine') ?? false,
            $offset
        )), Response::HTTP_OK);
    }

    public function match(Request $request, GameMatch $match): JsonResponse
    {
        $detail = $this->feedService->match($this->actor($request), $match);

        return $this->successResponse(
            (new FeedMatchResource($detail['match']))->resolve($request) + ['events' => $detail['events'], 'lineups' => $detail['lineups']],
            Response::HTTP_OK
        );
    }

    public function stats(Request $request, Tournament $tournament): JsonResponse
    {
        return $this->successResponse($this->feedService->stats($this->actor($request), $tournament), Response::HTTP_OK);
    }

    /**
     * @param  array{data: Collection<int, GameMatch>, next_cursor: string|null}  $page
     * @return array<string, mixed>
     */
    private function pageResponse(array $page): array
    {
        return ['data' => FeedMatchResource::collection($page['data']), 'next_cursor' => $page['next_cursor']];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
