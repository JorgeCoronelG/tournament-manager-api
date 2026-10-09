<?php

namespace App\Data\Referees;

use Spatie\LaravelData\Data;

class RefereeData extends Data
{
    public function __construct(
        public string $first_name,
        public string $last_name,
        public string $email,
        public ?string $phone = null,
    ) {}
}
