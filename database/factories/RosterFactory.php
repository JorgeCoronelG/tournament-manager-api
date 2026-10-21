<?php

namespace Database\Factories;

use App\Core\Enum\RosterStatus;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El torneo sale de la inscripción: la clave compuesta (registration_id, tournament_id) debe coincidir.
 *
 * @extends Factory<Roster>
 */
class RosterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $registration = Registration::factory()->create();

        return [
            'registration_id' => $registration->id,
            'tournament_id' => $registration->tournament_id,
            'player_id' => Player::factory(),
            'jersey_number' => fake()->numberBetween(1, 99),
            'status' => RosterStatus::ACTIVE,
        ];
    }

    public function status(RosterStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function forRegistration(Registration $registration): static
    {
        return $this->state(['registration_id' => $registration->id, 'tournament_id' => $registration->tournament_id]);
    }
}
