<?php

namespace Database\Factories;

use App\Core\Enum\Sport;
use App\Core\Enum\Tiebreaker;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Tournament;
use App\Models\TournamentGroup;
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
            'rounds' => Tournament::DEFAULT_ROUNDS,
            'match_duration_minutes' => Tournament::DEFAULT_MATCH_DURATION_MINUTES,
            'tiebreakers' => Tiebreaker::defaults(),
            'walkover_goals' => Tournament::DEFAULT_WALKOVER_GOALS,
        ];
    }

    /**
     * Todo torneo nace con su grupo de fase regular, igual que al crearlo por el API.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tournament $tournament) {
            $group = new TournamentGroup;
            $group->forceFill([
                'tournament_id' => $tournament->id,
                'name' => TournamentGroup::DEFAULT_NAME,
                'type' => TournamentGroupType::REGULAR_STAGE,
            ])->save();
        });
    }

    public function status(TournamentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
