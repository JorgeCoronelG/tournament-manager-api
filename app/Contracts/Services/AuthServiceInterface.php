<?php

namespace App\Contracts\Services;

use App\Data\Auth\LoginData;
use App\Models\User;

interface AuthServiceInterface
{
    /**
     * @return array{user: User, token: string}
     */
    public function login(LoginData $data): array;

    public function logout(User $user): void;
}
