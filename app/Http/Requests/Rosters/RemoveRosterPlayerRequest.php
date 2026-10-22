<?php

namespace App\Http\Requests\Rosters;

use Illuminate\Foundation\Http\FormRequest;

class RemoveRosterPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('removal_reason');

        if (is_string($reason)) {
            $this->merge(['removal_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'removal_reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['removal_reason' => 'motivo'];
    }

    public function reason(): string
    {
        return $this->string('removal_reason')->toString();
    }
}
