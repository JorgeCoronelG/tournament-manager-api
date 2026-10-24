<?php

namespace App\Http\Requests\Playoffs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre de la fase regular: por liguilla, los cruces de primera ronda (opcionales; si faltan se usan los propuestos) y
 * una fecha por partido. Que los equipos sean los clasificados y que las fechas sean válidas lo valida PlayoffService.
 */
class ClosePhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'liguillas' => ['required', 'array', 'min:1'],
            'liguillas.*.group_id' => ['required', 'integer', 'min:1'],
            'liguillas.*.ties' => ['nullable', 'array'],
            'liguillas.*.ties.*.bracket_position' => ['required', 'integer', 'min:1'],
            'liguillas.*.ties.*.team_a_id' => ['required', 'integer', 'min:1'],
            'liguillas.*.ties.*.team_b_id' => ['required', 'integer', 'min:1'],
            'liguillas.*.round_dates' => ['required', 'array', 'min:1'],
            'liguillas.*.round_dates.*' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'liguillas' => 'liguillas',
            'liguillas.*.group_id' => 'liguilla',
            'liguillas.*.ties' => 'cruces',
            'liguillas.*.ties.*.bracket_position' => 'posición del cruce',
            'liguillas.*.ties.*.team_a_id' => 'equipo A',
            'liguillas.*.ties.*.team_b_id' => 'equipo B',
            'liguillas.*.round_dates' => 'fechas de los partidos',
            'liguillas.*.round_dates.*' => 'fecha del partido',
        ];
    }

    /**
     * @return list<array{group_id: int, ties?: list<array{bracket_position: int, team_a_id: int, team_b_id: int}>|null, round_dates: list<string>}>
     */
    public function entries(): array
    {
        /** @var list<array{group_id: int, ties?: list<array{bracket_position: int, team_a_id: int, team_b_id: int}>|null, round_dates: list<string>}> $entries */
        $entries = array_values((array) $this->validated('liguillas'));

        return $entries;
    }
}
