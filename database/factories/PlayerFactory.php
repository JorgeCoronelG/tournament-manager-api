<?php

namespace Database\Factories;

use App\Core\Enum\PlayerGender;
use App\Models\Player;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'birth_date' => fake()->dateTimeBetween('-40 years', '-18 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(PlayerGender::cases()),
            'qr_code' => Str::random(32),
        ];
    }

    public function gender(PlayerGender $gender): static
    {
        return $this->state(['gender' => $gender]);
    }
}
