<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\GroupServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Groups\AssignGroupsRequest;
use App\Http\Requests\Groups\SaveGroupRequest;
use App\Http\Resources\Groups\GroupDetailResource;
use App\Http\Resources\Groups\GroupRegistrationResource;
use App\Http\Resources\Groups\GroupResource;
use App\Models\League;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GroupController extends BaseApiController
{
    public function __construct(protected GroupServiceInterface $groupService) {}

    public function index(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        return $this->overviewResponse($tournament);
    }

    public function show(League $league, Tournament $tournament, TournamentGroup $group): JsonResponse
    {
        $group->load(['sources.sourceGroup', 'tournament']);

        return $this->showOne(new GroupDetailResource($this->groupService->detail($group)));
    }

    public function store(SaveGroupRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $group = $this->groupService->createGroup($tournament, $request->attributesToSave());

        return $this->showOne(new GroupResource($group->load(['sources.sourceGroup', 'tournament'])), Response::HTTP_CREATED);
    }

    public function update(SaveGroupRequest $request, League $league, Tournament $tournament, TournamentGroup $group): JsonResponse
    {
        $group = $this->groupService->updateGroup($tournament, $group, $request->attributesToSave());

        return $this->showOne(new GroupResource($group->load(['sources.sourceGroup', 'tournament'])));
    }

    public function destroy(League $league, Tournament $tournament, TournamentGroup $group): Response
    {
        $this->groupService->deleteGroup($tournament, $group);

        return $this->noContentResponse();
    }

    public function assign(AssignGroupsRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $this->groupService->assign($tournament, $request->assignments());

        return $this->overviewResponse($tournament);
    }

    public function randomAssignment(League $league, Tournament $tournament): JsonResponse
    {
        $this->groupService->randomAssignment($tournament);

        return $this->overviewResponse($tournament);
    }

    private function overviewResponse(Tournament $tournament): JsonResponse
    {
        $overview = $this->groupService->overview($tournament);

        return $this->successResponse([
            'groups' => GroupResource::collection($overview['groups'])->resolve(),
            'unassigned' => GroupRegistrationResource::collection($overview['unassigned'])->resolve(),
            'finals_aligned' => $overview['finals_aligned'],
        ], Response::HTTP_OK);
    }
}
