<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Victoria por default de un partido: el equipo que no se presentó (uno de los dos del partido, lo valida el servicio)
 * y una nota opcional.
 */
class WalkoverRequest extends FormRequest
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
            'loser_registration_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'loser_registration_id' => 'equipo que no se presentó',
            'reason' => 'motivo',
        ];
    }

    public function loserRegistrationId(): int
    {
        return $this->integer('loser_registration_id');
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
