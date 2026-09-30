<?php

namespace App\Contracts\Services;

use App\Data\Auth\ActivateAccountData;
use App\Data\Auth\ForgotPasswordData;
use App\Data\Auth\ResetPasswordData;

interface PasswordResetServiceInterface
{
    public function forgotPassword(ForgotPasswordData $data): void;

    public function resetPassword(ResetPasswordData $data): void;

    public function activateAccount(ActivateAccountData $data): void;
}
