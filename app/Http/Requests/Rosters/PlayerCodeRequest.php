<?php

namespace App\Http\Requests\Rosters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clave exacta de un jugador (8 caracteres en los registros nuevos; el seeder usa otras longitudes). Se compara en mayúsculas y sin espacios.
 */
class PlayerCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('user_code');

        if (is_string($code)) {
            $this->merge(['user_code' => mb_strtoupper(trim($code))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_code' => ['required', 'string', 'min:4', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['user_code' => 'clave del jugador'];
    }

    public function userCode(): string
    {
        return $this->string('user_code')->toString();
    }
}
