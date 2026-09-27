<?php

namespace App\Contracts\Repositories;

use App\Models\PasswordResetToken;
use Carbon\CarbonInterface;

interface PasswordResetRepositoryInterface
{
    public function findByEmail(string $email): ?PasswordResetToken;

    /**
     * Crea el código para el correo dado, reemplazando cualquier código
     * anterior no usado (email es la llave primaria de la tabla).
     */
    public function createCode(string $email, string $hashedCode, CarbonInterface $expiresAt): PasswordResetToken;

    public function incrementAttempts(PasswordResetToken $passwordResetToken): void;

    public function delete(string $email): void;
}
