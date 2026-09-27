<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function createToken(User $user): string;

    public function revokeCurrentToken(User $user): void;
}
