<?php

namespace App\Data\Leagues;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class LeagueData extends Data
{
    public function __construct(
        public string $name,
        public int $admin_user_id,
        public ?int $max_active_tournaments,
        // Reservado para los planes futuros: hoy no se configura. Optional distingue «no vino» (no se modifica) de null.
        public int|Optional|null $max_teams_per_tournament = new Optional,
    ) {}
}
