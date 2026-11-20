<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\BaseApiController;
use App\Http\Resources\Rosters\PlayerRosterRequestResource;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App móvil, rol Jugador: sus solicitudes de plantilla pendientes y la respuesta (aceptar o rechazar).
 */
class PlayerRosterRequestController extends BaseApiController
{
    public function __construct(protected RosterServiceInterface $rosterService) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            ['data' => PlayerRosterRequestResource::collection($this->rosterService->pendingRequestsOf($this->actor($request)))],
            Response::HTTP_OK
        );
    }

    public function accept(Request $request, Roster $roster): JsonResponse
    {
        return $this->respond($this->rosterService->accept($roster, $this->actor($request)));
    }

    public function reject(Request $request, Roster $roster): JsonResponse
    {
        return $this->respond($this->rosterService->reject($roster, $this->actor($request)));
    }

    private function respond(Roster $roster): JsonResponse
    {
        return $this->showOne(new PlayerRosterRequestResource($roster->load([
            'registration' => fn ($registration) => $registration->withTrashed()->with('team'),
            'tournament.league',
            'requestedBy',
        ])));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
