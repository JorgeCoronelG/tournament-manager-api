<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\SignupServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Auth\BecomePlayerRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Resources\Auth\LoginResource;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SignupController extends BaseApiController
{
    public function __construct(protected SignupServiceInterface $signupService) {}

    /**
     * Público. Responde igual que el login para que la app quede con la sesión iniciada.
     */
    public function store(SignupRequest $request): JsonResponse
    {
        $result = $this->signupService->register($request->toData());

        return $this->showOne(new LoginResource($result), Response::HTTP_CREATED);
    }

    /**
     * Delegado o árbitro que también quiere jugar. Responde como GET /user, ya con el rol jugador.
     */
    public function becomePlayer(BecomePlayerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->showOne(new UserResource($this->signupService->becomePlayer($user, $request->toData())), Response::HTTP_CREATED);
    }
}
