<?php

namespace App\Data\Teams;

use App\Core\Enum\Sport;
use Spatie\LaravelData\Data;

class CreateTeamData extends Data
{
    public function __construct(
        public string $name,
        public ?ManagerInputData $manager = null,
        public ?Sport $sport = null,
    ) {}
}
