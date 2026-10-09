<?php

namespace App\Services;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Services\PasswordResetServiceInterface;
use App\Core\Enum\LoginClient;
use App\Core\Enum\Message;
use App\Data\Auth\ActivateAccountData;
use App\Data\Auth\ForgotPasswordData;
use App\Data\Auth\ResetPasswordData;
use App\Exceptions\CustomErrorException;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class PasswordResetService implements PasswordResetServiceInterface
{
    private const CODE_EXPIRES_IN_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        protected AuthRepositoryInterface $authRepository,
        protected PasswordResetRepositoryInterface $passwordResetRepository,
    ) {}

    /**
     * Solo para el panel web: usuario activo, con cuenta ya activada y con rol de administrador.
     * No revela nada: si no cumple, simplemente no hace nada (sin código ni correo), igual que
     * cuando el correo no existe. Los pendientes usan la invitación.
     */
    public function forgotPassword(ForgotPasswordData $data): void
    {
        $user = $this->authRepository->findByEmail($data->email);

        if ($user === null || ! $this->canResetPassword($user)) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        $this->passwordResetRepository->createCode(
            $data->email,
            Hash::make($code),
            now()->addMinutes(self::CODE_EXPIRES_IN_MINUTES)
        );

        $user->notify(new PasswordResetCodeNotification($code, self::CODE_EXPIRES_IN_MINUTES));
    }

    /**
     * @throws CustomErrorException
     */
    public function resetPassword(ResetPasswordData $data): void
    {
        $this->consumeValidCode($data->email, $data->code);

        $user = $this->authRepository->findByEmail($data->email);

        // El usuario pudo perder el rol o ser desactivado después de pedir el código.
        if ($user === null || ! $this->canResetPassword($user)) {
            $this->passwordResetRepository->delete($data->email);

            throw new CustomErrorException(Message::CODE_INVALID_OR_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->authRepository->updatePassword($user, $data->password);
        $this->authRepository->revokeAllTokens($user);
        $this->passwordResetRepository->delete($data->email);
    }

    /**
     * Solo aplica a cuentas pendientes (email_verified_at NULL); si ya está
     * activa, se responde el mismo error genérico que un código inválido
     * para no revelar el estado de la cuenta.
     *
     * @throws CustomErrorException
     */
    public function activateAccount(ActivateAccountData $data): void
    {
        $user = $this->authRepository->findByEmail($data->email);

        if ($user === null || $user->email_verified_at !== null) {
            throw new CustomErrorException(Message::CODE_INVALID_OR_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consumeValidCode($data->email, $data->code);

        $this->authRepository->updatePassword($user, $data->password);
        $this->authRepository->markEmailVerified($user);
        $this->authRepository->revokeAllTokens($user);
        $this->passwordResetRepository->delete($data->email);
    }

    private function canResetPassword(User $user): bool
    {
        $user->loadMissing('roles');

        return $user->is_active
            && $user->email_verified_at !== null
            && $user->hasRoleForClient(LoginClient::WEB);
    }

    /**
     * Valida el código hasheado (expiración e intentos) para un correo dado.
     * Incrementa los intentos y lanza la excepción correspondiente si falla.
     *
     * @throws CustomErrorException
     */
    private function consumeValidCode(string $email, string $code): void
    {
        $passwordReset = $this->passwordResetRepository->findByEmail($email);

        if ($passwordReset === null || $passwordReset->expires_at->isPast()) {
            throw new CustomErrorException(Message::CODE_INVALID_OR_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($passwordReset->attempts >= self::MAX_ATTEMPTS) {
            throw new CustomErrorException(Message::TOO_MANY_CODE_ATTEMPTS, Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (! Hash::check($code, $passwordReset->code)) {
            $this->passwordResetRepository->incrementAttempts($passwordReset);

            throw new CustomErrorException(Message::CODE_INVALID_OR_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
