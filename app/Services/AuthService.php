<?php

namespace App\Services;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Services\AuthServiceInterface;
use App\Core\Enum\Message;
use App\Data\Auth\ChangePasswordData;
use App\Data\Auth\LoginData;
use App\Exceptions\CustomErrorException;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthService implements AuthServiceInterface
{
    public function __construct(protected AuthRepositoryInterface $authRepository) {}

    /**
     * @return array{user: User, token: string}
     *
     * @throws CustomErrorException
     */
    public function login(LoginData $data): array
    {
        $user = $this->authRepository->findByEmail($data->email);

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw new CustomErrorException(Message::CREDENTIALS_INVALID, Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->authRepository->createToken($user);

        $user->load('roles');

        return ['user' => $user, 'token' => $token];
    }

    public function logout(User $user): void
    {
        $this->authRepository->revokeCurrentToken($user);
    }

    /**
     * @throws CustomErrorException
     */
    public function changePassword(User $user, ChangePasswordData $data): void
    {
        if (! Hash::check($data->current_password, $user->password)) {
            throw new CustomErrorException(Message::CURRENT_PASSWORD_INVALID, Response::HTTP_UNAUTHORIZED);
        }

        $this->authRepository->updatePassword($user, $data->password);
        $this->authRepository->revokeOtherTokens($user);
    }
}
