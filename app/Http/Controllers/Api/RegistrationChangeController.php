<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RegistrationChangeServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Registrations\ReplaceRegistrationRequest;
use App\Http\Requests\Registrations\WithdrawRegistrationRequest;
use App\Http\Resources\Registrations\RegistrationResource;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RegistrationChangeController extends BaseApiController
{
    public function __construct(protected RegistrationChangeServiceInterface $changeService) {}

    public function withdrawPreview(League $league, Tournament $tournament, Registration $registration): JsonResponse
    {
        return $this->successResponse($this->changeService->withdrawPreview($tournament, $registration), Response::HTTP_OK);
    }

    public function teamChanges(League $league, Tournament $tournament, Registration $registration): JsonResponse
    {
        return $this->successResponse(['data' => $this->changeService->teamChanges($registration)], Response::HTTP_OK);
    }

    public function withdraw(WithdrawRegistrationRequest $request, League $league, Tournament $tournament, Registration $registration): JsonResponse
    {
        $result = $this->changeService->withdraw($tournament, $registration, $request->reason(), (int) $request->user()?->id);

        return $this->successResponse([
            'kept_played' => $result['kept_played'],
            'pending_matches' => $result['pending_matches'],
            'players_released' => $result['players_released'],
            'registration' => (new RegistrationResource($result['registration']))->resolve(),
        ], Response::HTTP_OK);
    }

    public function replace(ReplaceRegistrationRequest $request, League $league, Tournament $tournament, Registration $registration): JsonResponse
    {
        $result = $this->changeService->replace($tournament, $registration, $request->teamId(), $request->reason(), (int) $request->user()?->id);

        return $this->successResponse([
            'registration' => (new RegistrationResource($result['registration']))->resolve(),
            'warnings' => $result['warnings'],
        ], Response::HTTP_OK);
    }
}
