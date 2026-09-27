<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

class ResetPasswordData extends Data
{
    public function __construct(
        public string $email,
        public string $code,
        public string $password,
    ) {}
}
