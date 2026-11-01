<?php

namespace App\Http\Requests\Rosters;

/**
 * El delegado manda la solicitud: clave exacta del jugador y su dorsal.
 */
class SendRosterRequestRequest extends PlayerCodeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'jersey_number' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + ['jersey_number' => 'dorsal'];
    }

    public function jerseyNumber(): int
    {
        return $this->integer('jersey_number');
    }
}
