<?php

namespace Database\Factories;

use App\Core\Enum\Sport;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'name' => 'Torneo '.fake()->unique()->words(2, true),
            'sport' => Sport::default(),
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'start_date' => fake()->date(),
            'status' => TournamentStatus::PLANNING,
        ];
    }

    public function status(TournamentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
