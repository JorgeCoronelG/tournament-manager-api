<?php

namespace Database\Factories;

use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TournamentGroupType;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentGroup;
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
            // Igual que al inscribir: con un único grupo de fase regular se asigna; con varios queda sin grupo.
            'group_id' => function (array $attributes) {
                $groups = TournamentGroup::query()
                    ->where('tournament_id', $attributes['tournament_id'])
                    ->where('type', TournamentGroupType::REGULAR_STAGE)
                    ->pluck('id');

                return $groups->count() === 1 ? $groups->first() : null;
            },
            'status' => RegistrationStatus::ACTIVE,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(['status' => RegistrationStatus::WITHDRAWN, 'withdrawn_at' => now(), 'withdrawn_reason' => 'Baja de prueba']);
    }

    public function status(RegistrationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
