<?php

namespace App\Data\Leagues;

use Spatie\LaravelData\Data;

class LeagueData extends Data
{
    public function __construct(
        public string $name,
        public int $admin_user_id,
    ) {}
}
