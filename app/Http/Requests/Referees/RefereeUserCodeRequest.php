<?php

namespace App\Http\Requests\Referees;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lookup (query string) y attach (body) reciben la misma clave del árbitro.
 */
class RefereeUserCodeRequest extends FormRequest
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
            'user_code' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_code' => 'clave del árbitro',
        ];
    }

    public function userCode(): string
    {
        return trim($this->string('user_code')->toString());
    }
}
