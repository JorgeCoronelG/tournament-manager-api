<?php

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

class TeamData extends Data
{
    public function __construct(public string $name) {}
}
