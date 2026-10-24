<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcador de un partido desde el panel. Los goles llegan como `home_goals`/`away_goals` (o sus alias `home_score`/`away_score`);
 * los penales son opcionales y solo los acepta la liguilla (las reglas de negocio las valida el servicio).
 */
class MatchResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'home_goals' => $this->input('home_goals', $this->input('home_score')),
            'away_goals' => $this->input('away_goals', $this->input('away_score')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'home_goals' => ['required', 'integer', 'min:0', 'max:99'],
            'away_goals' => ['required', 'integer', 'min:0', 'max:99'],
            'home_penalties' => ['nullable', 'integer', 'min:0', 'max:99', 'required_with:away_penalties'],
            'away_penalties' => ['nullable', 'integer', 'min:0', 'max:99', 'required_with:home_penalties'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'home_goals' => 'marcador local',
            'away_goals' => 'marcador visitante',
            'home_penalties' => 'penales del local',
            'away_penalties' => 'penales del visitante',
        ];
    }

    public function homeGoals(): int
    {
        return $this->integer('home_goals');
    }

    public function awayGoals(): int
    {
        return $this->integer('away_goals');
    }

    public function homePenalties(): ?int
    {
        return $this->filled('home_penalties') ? $this->integer('home_penalties') : null;
    }

    public function awayPenalties(): ?int
    {
        return $this->filled('away_penalties') ? $this->integer('away_penalties') : null;
    }
}
