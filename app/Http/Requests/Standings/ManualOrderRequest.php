<?php

namespace App\Http\Requests\Standings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Orden manual de un conjunto de equipos empatados. Que de verdad estén empatados lo valida StandingService.
 */
class ManualOrderRequest extends FormRequest
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
            'group_id' => ['required', 'integer', 'min:1'],
            'registration_ids' => ['required', 'array', 'min:2'],
            'registration_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'group_id' => 'grupo',
            'registration_ids' => 'equipos',
            'registration_ids.*' => 'equipo',
        ];
    }

    /**
     * @return list<int>
     */
    public function registrationIds(): array
    {
        return array_values(array_map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0, (array) $this->input('registration_ids')));
    }
}
