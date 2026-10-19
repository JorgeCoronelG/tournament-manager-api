<?php

namespace Database\Factories;

use App\Core\Enum\FieldStatus;
use App\Models\Field;
use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Field>
 */
class FieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'name' => 'Cancha '.fake()->unique()->words(2, true),
            'status' => FieldStatus::ACTIVE,
        ];
    }

    public function status(FieldStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
