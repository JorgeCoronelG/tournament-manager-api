<?php

namespace App\Http\Requests\Auth;

use App\Data\Auth\ActivateAccountData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ActivateAccountRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
            'code' => 'código',
            'password' => 'contraseña',
        ];
    }

    public function toData(): ActivateAccountData
    {
        return ActivateAccountData::from($this->validated());
    }
}
