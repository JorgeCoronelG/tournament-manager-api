<?php

namespace App\Data\Auth;

use App\Core\Enum\PlayerGender;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

class SignupData
{
    public function __construct(
        public string $first_name,
        public string $last_name,
        public string $email,
        public string $phone,
        public string $password,
        public CarbonImmutable $birth_date,
        public PlayerGender $gender,
        public UploadedFile $photo,
    ) {}
}
