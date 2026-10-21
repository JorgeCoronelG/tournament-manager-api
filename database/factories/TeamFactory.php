<?php

namespace Database\Factories;

use App\Core\Enum\Sport;
use App\Core\Enum\TeamStatus;
use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'manager_user_id' => null,
            'name' => fake()->unique()->words(2, true),
            'sport' => Sport::default(),
            'logo_path' => null,
            'status' => TeamStatus::ACTIVE,
        ];
    }

    public function status(TeamStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
