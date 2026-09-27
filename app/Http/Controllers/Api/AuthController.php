<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\AuthServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\LoginResource;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends BaseApiController
{
    public function __construct(protected AuthServiceInterface $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->toData());

        return $this->showOne(new LoginResource($result), Response::HTTP_OK);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authService->logout($user);

        return $this->noContentResponse();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->showOne(new UserResource($user));
    }
}
