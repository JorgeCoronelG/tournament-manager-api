<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\RosterStatus;
use App\Http\Requests\Rosters\RemoveRosterPlayerRequest;
use App\Http\Resources\Rosters\RosterResource;
use App\Models\League;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RosterController extends BaseApiController
{
    public function __construct(protected RosterServiceInterface $rosterService) {}

    public function index(Request $request, League $league, Tournament $tournament, Registration $registration): JsonResponse
    {
        $result = $this->rosterService->listForRegistration(
            $registration,
            ListQuery::fromRequest($request),
            $this->parseSearch($request),
            $this->parseEnum($request, 'status', RosterStatus::class)
        );

        // `summary` cuenta toda la plantilla, no solo la página ni la búsqueda.
        return $this->successResponse(
            $this->getPaginationCollection(RosterResource::collection($result['rows'])) + ['summary' => $result['summary']],
            Response::HTTP_OK
        );
    }

    public function remove(RemoveRosterPlayerRequest $request, League $league, Tournament $tournament, Registration $registration, Roster $roster): JsonResponse
    {
        return $this->respond($this->rosterService->removePlayer($roster, $this->actor($request), $request->reason()));
    }

    public function authorizeRejoin(Request $request, League $league, Tournament $tournament, Registration $registration, Roster $roster): JsonResponse
    {
        return $this->respond($this->rosterService->authorizeRejoin($roster, $this->actor($request)));
    }

    public function revokeRejoin(Request $request, League $league, Tournament $tournament, Registration $registration, Roster $roster): JsonResponse
    {
        return $this->respond($this->rosterService->revokeRejoin($roster, $this->actor($request)));
    }

    private function respond(Roster $roster): JsonResponse
    {
        return $this->showOne(new RosterResource($roster->load(['player' => fn ($player) => $player->withTrashed()->with('user'), 'requestedBy', 'removedBy'])));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
