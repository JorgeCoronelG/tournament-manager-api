<?php

namespace App\Data\Teams;

use App\Core\Enum\Sport;
use Spatie\LaravelData\Data;

class TeamData extends Data
{
    public function __construct(
        public string $name,
        public ?Sport $sport = null,
    ) {}
}
