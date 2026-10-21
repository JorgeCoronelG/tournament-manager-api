<?php

namespace App\Data\Tournaments;

use App\Core\Enum\TournamentGender;
use Spatie\LaravelData\Data;

class TournamentData extends Data
{
    public function __construct(
        public string $name,
        public TournamentGender $gender,
        public string $start_date,
        public ?int $min_age = null,
        public ?int $max_age = null,
        public ?int $max_roster_size = null,
        public ?int $min_matches_for_playoffs = null,
    ) {}
}
