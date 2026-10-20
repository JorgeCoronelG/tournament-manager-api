<?php

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

class CreateTeamData extends Data
{
    public function __construct(
        public string $name,
        public ?ManagerInputData $manager = null,
    ) {}
}
