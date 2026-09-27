<?php

namespace App\Repositories;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthRepository implements AuthRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function createToken(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();
        $token->delete();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeOtherTokens(User $user): void
    {
        /** @var PersonalAccessToken $currentToken */
        $currentToken = $user->currentAccessToken();

        $user->tokens()->where('id', '!=', $currentToken->id)->delete();
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
    }
}
