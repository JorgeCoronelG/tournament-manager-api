<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\TournamentServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Http\Requests\Tournaments\SaveTournamentRequest;
use App\Http\Requests\Tournaments\UpdateTournamentStatusRequest;
use App\Http\Resources\Tournaments\TournamentResource;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TournamentController extends BaseApiController
{
    public function __construct(protected TournamentServiceInterface $tournamentService) {}

    public function index(Request $request, League $league): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= '-start_date';

        $tournaments = $this->tournamentService->paginateByLeague(
            $league,
            $listQuery,
            $this->parseSearch($request),
            $this->parseEnum($request, 'gender', TournamentGender::class),
            $this->parseEnum($request, 'status', TournamentStatus::class)
        );

        return $this->showAll(TournamentResource::collection($tournaments));
    }

    public function store(SaveTournamentRequest $request, League $league): JsonResponse
    {
        $tournament = $this->tournamentService->createInLeague($league, $request->toData());

        return $this->showOne(new TournamentResource($tournament), Response::HTTP_CREATED);
    }

    public function show(League $league, Tournament $tournament): JsonResponse
    {
        return $this->showOne(new TournamentResource($tournament));
    }

    public function update(SaveTournamentRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $tournament = $this->tournamentService->updateTournament($tournament, $request->toData());

        return $this->showOne(new TournamentResource($tournament));
    }

    public function updateStatus(UpdateTournamentStatusRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $status = TournamentStatus::from($request->string('status')->toString());

        return $this->showOne(new TournamentResource($this->tournamentService->changeStatus($tournament, $status)));
    }

    public function destroy(League $league, Tournament $tournament): Response
    {
        $this->tournamentService->deleteTournament($tournament);

        return $this->noContentResponse();
    }

    private function parseSearch(Request $request): ?string
    {
        $search = trim((string) $request->string('search'));

        return $search === '' ? null : $search;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     *
     * @throws CustomErrorException
     */
    private function parseEnum(Request $request, string $key, string $enum): ?\BackedEnum
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = $request->query($key);
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return $case;
    }
}
