<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PasswordResetServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Auth\ActivateAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PasswordResetController extends BaseApiController
{
    public function __construct(protected PasswordResetServiceInterface $passwordResetService) {}

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->forgotPassword($request->toData());

        return $this->successResponse(
            ['message' => 'Si el correo existe, se envió un código de verificación.'],
            Response::HTTP_OK
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->resetPassword($request->toData());

        return $this->successResponse(
            ['message' => 'Contraseña actualizada correctamente.'],
            Response::HTTP_OK
        );
    }

    public function activateAccount(ActivateAccountRequest $request): JsonResponse
    {
        $this->passwordResetService->activateAccount($request->toData());

        return $this->successResponse(
            ['message' => 'Cuenta activada correctamente.'],
            Response::HTTP_OK
        );
    }
}
