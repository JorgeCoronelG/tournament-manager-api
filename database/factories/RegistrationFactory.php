<?php

namespace Database\Factories;

use App\Core\Enum\RegistrationStatus;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'tournament_id' => Tournament::factory(),
            'status' => RegistrationStatus::ACTIVE,
        ];
    }

    public function status(RegistrationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
