<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\TeamServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\TeamStatus;
use App\Exceptions\CustomErrorException;
use App\Http\Requests\Teams\AssignTeamManagerRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Http\Requests\Teams\TeamManagerCodeRequest;
use App\Http\Requests\Teams\UpdateTeamManagerRequest;
use App\Http\Requests\Teams\UpdateTeamStatusRequest;
use App\Http\Requests\Teams\UploadTeamLogoRequest;
use App\Http\Resources\Teams\TeamManagerLookupResource;
use App\Http\Resources\Teams\TeamManagerOptionResource;
use App\Http\Resources\Teams\TeamResource;
use App\Models\League;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TeamController extends BaseApiController
{
    public function __construct(protected TeamServiceInterface $teamService) {}

    public function index(Request $request, League $league): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= 'name';

        $teams = $this->teamService->paginateByLeague(
            $league,
            $listQuery,
            $this->parseSearch($request),
            $this->parseEnum($request, 'status', TeamStatus::class),
            $this->parseBool($request, 'has_manager'),
            $this->parseManagerUserId($request)
        );

        return $this->showAll(TeamResource::collection($teams));
    }

    public function managers(League $league): JsonResponse
    {
        return $this->showAll(TeamManagerOptionResource::collection($this->teamService->managersByLeague($league)));
    }

    public function store(SaveTeamRequest $request, League $league): JsonResponse
    {
        $team = $this->teamService->createTeam($league, $request->toCreateData());

        return $this->showOne(new TeamResource($team), Response::HTTP_CREATED);
    }

    public function show(League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($team->load('manager')));
    }

    public function update(SaveTeamRequest $request, League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->updateTeam($team, $request->toData())));
    }

    public function updateStatus(UpdateTeamStatusRequest $request, League $league, Team $team): JsonResponse
    {
        $status = TeamStatus::from($request->string('status')->toString());

        return $this->showOne(new TeamResource($this->teamService->changeStatus($team, $status)));
    }

    public function destroy(League $league, Team $team): Response
    {
        $this->teamService->deleteTeam($team);

        return $this->noContentResponse();
    }

    public function uploadLogo(UploadTeamLogoRequest $request, League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->setLogo($team, $request->logo())));
    }

    public function destroyLogo(League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->removeLogo($team)));
    }

    public function managerLookup(TeamManagerCodeRequest $request, League $league): JsonResponse
    {
        return $this->showOne(new TeamManagerLookupResource($this->teamService->lookupManager($request->userCode())));
    }

    public function assignManager(AssignTeamManagerRequest $request, League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->assignManager($team, $request->toInput())));
    }

    public function updateManager(UpdateTeamManagerRequest $request, League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->updateManager($team, $request->toData())));
    }

    public function destroyManager(League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->removeManager($team)));
    }

    public function resendManagerInvitation(League $league, Team $team): JsonResponse
    {
        return $this->showOne(new TeamResource($this->teamService->resendManagerInvitation($team)));
    }

    /**
     * @throws CustomErrorException
     */
    private function parseManagerUserId(Request $request): ?int
    {
        if (! $request->filled('manager_user_id')) {
            return null;
        }

        $value = $request->query('manager_user_id');

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return (int) $value;
    }
}
