<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

class ForgotPasswordData extends Data
{
    public function __construct(
        public string $email,
    ) {}
}
