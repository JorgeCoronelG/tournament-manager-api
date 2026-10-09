<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    /**
     * El nombre del token guarda el cliente (web o mobile) para auditoría.
     */
    public function createToken(User $user, string $name = 'auth_token'): string;

    public function revokeCurrentToken(User $user): void;

    public function revokeAllTokens(User $user): void;

    public function revokeOtherTokens(User $user): void;

    public function updatePassword(User $user, string $password): void;

    public function markEmailVerified(User $user): void;
}
