<?php

namespace App\Http\Requests\Auth;

use App\Data\Auth\ForgotPasswordData;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
        ];
    }

    public function toData(): ForgotPasswordData
    {
        return ForgotPasswordData::from($this->validated());
    }
}
