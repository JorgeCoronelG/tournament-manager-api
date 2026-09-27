<?php

namespace App\Repositories;

use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Models\PasswordResetToken;
use Carbon\CarbonInterface;

class PasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function findByEmail(string $email): ?PasswordResetToken
    {
        return PasswordResetToken::query()->find($email);
    }

    public function createCode(string $email, string $hashedCode, CarbonInterface $expiresAt): PasswordResetToken
    {
        return PasswordResetToken::query()->updateOrCreate(
            ['email' => $email],
            ['code' => $hashedCode, 'attempts' => 0, 'expires_at' => $expiresAt, 'created_at' => now()]
        );
    }

    public function incrementAttempts(PasswordResetToken $passwordResetToken): void
    {
        $passwordResetToken->increment('attempts');
    }

    public function delete(string $email): void
    {
        PasswordResetToken::query()->where('email', $email)->delete();
    }
}
