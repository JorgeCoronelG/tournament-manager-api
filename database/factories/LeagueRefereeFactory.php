<?php

namespace Database\Factories;

use App\Core\Enum\LeagueRefereeStatus;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeagueReferee>
 */
class LeagueRefereeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'user_id' => User::factory(),
            'status' => LeagueRefereeStatus::ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => LeagueRefereeStatus::INACTIVE]);
    }
}
