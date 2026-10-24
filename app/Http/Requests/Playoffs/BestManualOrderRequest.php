<?php

namespace App\Http\Requests\Playoffs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Orden manual de los candidatos empatados de una fuente de mejores de una posición. Que de verdad estén empatados lo
 * valida PlayoffService.
 */
class BestManualOrderRequest extends FormRequest
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
            'team_ids' => ['required', 'array', 'min:2'],
            'team_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'team_ids' => 'equipos',
            'team_ids.*' => 'equipo',
        ];
    }

    /**
     * @return list<int>
     */
    public function teamIds(): array
    {
        return array_values(array_map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0, (array) $this->input('team_ids')));
    }
}
