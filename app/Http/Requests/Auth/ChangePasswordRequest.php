<?php

namespace App\Http\Requests\Auth;

use App\Data\Auth\ChangePasswordData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'contraseña actual',
            'password' => 'nueva contraseña',
        ];
    }

    public function toData(): ChangePasswordData
    {
        return ChangePasswordData::from($this->validated());
    }
}
