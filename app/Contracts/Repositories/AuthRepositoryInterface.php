<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function createToken(User $user): string;

    public function revokeCurrentToken(User $user): void;

    public function revokeAllTokens(User $user): void;

    public function updatePassword(User $user, string $password): void;
}
