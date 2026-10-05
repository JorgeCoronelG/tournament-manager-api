<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\LeagueServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use App\Http\Requests\Leagues\SaveLeagueRequest;
use App\Http\Resources\Leagues\LeagueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LeagueController extends BaseApiController
{
    public function __construct(protected LeagueServiceInterface $leagueService) {}

    public function index(Request $request): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= '-created_at';

        $leagues = $this->leagueService->paginate(
            $listQuery,
            $this->parseAdminUserId($request),
            $this->parseSearch($request)
        );

        return $this->showAll(LeagueResource::collection($leagues));
    }

    public function store(SaveLeagueRequest $request): JsonResponse
    {
        $league = $this->leagueService->create($request->toData());

        return $this->showOne(new LeagueResource($league), Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $league = $this->leagueService->findById($id);
        $league->loadMissing('admin');

        return $this->showOne(new LeagueResource($league));
    }

    public function update(SaveLeagueRequest $request, int $id): JsonResponse
    {
        $league = $this->leagueService->update($id, $request->toData());

        return $this->showOne(new LeagueResource($league));
    }

    public function destroy(int $id): Response
    {
        $this->leagueService->delete($id);

        return $this->noContentResponse();
    }

    /**
     * @throws CustomErrorException
     */
    private function parseAdminUserId(Request $request): ?int
    {
        if (! $request->filled('admin_user_id')) {
            return null;
        }

        $value = $request->query('admin_user_id');

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return (int) $value;
    }

    private function parseSearch(Request $request): ?string
    {
        $search = trim((string) $request->string('search'));

        return $search === '' ? null : $search;
    }
}
