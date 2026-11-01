<?php

namespace App\Data\Auth;

use App\Core\Enum\LoginClient;
use Spatie\LaravelData\Data;

class LoginData extends Data
{
    public function __construct(
        public string $password,
        public LoginClient $client,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
