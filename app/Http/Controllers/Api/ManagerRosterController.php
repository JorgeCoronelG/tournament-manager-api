<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Rosters\PlayerCodeRequest;
use App\Http\Requests\Rosters\SendRosterRequestRequest;
use App\Http\Resources\Rosters\ManagerRegistrationResource;
use App\Http\Resources\Rosters\ManagerRosterResource;
use App\Http\Resources\Rosters\PlayerLookupResource;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\RosterRequestReceivedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App móvil, rol Delegado: sus equipos inscritos, su plantilla y las solicitudes a jugadores por clave exacta.
 */
class ManagerRosterController extends BaseApiController
{
    public function __construct(protected RosterServiceInterface $rosterService) {}

    public function registrations(Request $request): JsonResponse
    {
        return $this->successResponse(
            ['data' => ManagerRegistrationResource::collection($this->rosterService->registrationsOfManager($this->actor($request)))],
            Response::HTTP_OK
        );
    }

    public function roster(Request $request, Registration $registration): JsonResponse
    {
        return $this->successResponse(
            ['data' => ManagerRosterResource::collection($this->rosterService->rosterForManager($registration, $this->actor($request)))],
            Response::HTTP_OK
        );
    }

    public function lookup(PlayerCodeRequest $request): JsonResponse
    {
        return $this->showOne(new PlayerLookupResource($this->rosterService->findPlayerByCode($request->userCode())));
    }

    public function store(SendRosterRequestRequest $request, Registration $registration): JsonResponse
    {
        $player = $this->rosterService->findPlayerByCode($request->userCode());
        $roster = $this->rosterService->requestPlayer($registration, $player, $request->jerseyNumber(), $this->actor($request));

        $player->user?->notify(new RosterRequestReceivedNotification(
            $registration->loadMissing('team'),
            Tournament::query()->findOrFail($registration->tournament_id)
        ));

        return $this->showOne(new ManagerRosterResource($roster->load(['player' => fn ($player) => $player->withTrashed()->with('user')])), Response::HTTP_CREATED);
    }

    public function cancel(Request $request, Registration $registration, Roster $roster): JsonResponse
    {
        $roster = $this->rosterService->cancelRequest($roster, $this->actor($request));

        return $this->showOne(new ManagerRosterResource($roster->load(['player' => fn ($player) => $player->withTrashed()->with('user')])));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
