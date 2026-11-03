<?php

namespace App\Http\Requests\Teams;

use App\Core\Enum\TeamStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /**
     * El motivo es obligatorio solo al vetar.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TeamStatus::class)],
            'reason' => ['required_if:status,'.TeamStatus::BANNED->value, 'nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'reason' => 'motivo',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
