<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio manual de horario de un partido. Todo es opcional: solo se actualiza lo que viene (null quita el valor).
 * Las reglas de negocio (conflictos, cancha y árbitro válidos) las valida MatchScheduleService.
 */
class UpdateMatchScheduleRequest extends FormRequest
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
            'match_datetime' => ['sometimes', 'nullable', 'date'],
            'field_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'referee_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'schedule_locked' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'match_datetime' => 'fecha y hora',
            'field_id' => 'cancha',
            'referee_user_id' => 'árbitro',
            'schedule_locked' => 'horario fijado',
        ];
    }

    /**
     * Solo las claves enviadas.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->safe()->only(['match_datetime', 'field_id', 'referee_user_id', 'schedule_locked']);
    }
}
