<?php

namespace App\Data\Auth;

use App\Core\Enum\PlayerGender;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

class BecomePlayerData
{
    public function __construct(
        /** null: el usuario ya tenía teléfono y no lo cambia. */
        public ?string $phone,
        public CarbonImmutable $birth_date,
        public PlayerGender $gender,
        public UploadedFile $photo,
    ) {}
}
