<?php

namespace App\Http\Requests\Auth;

use App\Core\Enum\LoginClient;
use App\Data\Auth\LoginData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
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
            // La app móvil entra con correo o con teléfono: basta uno de los dos.
            'email' => ['required_without:phone', 'nullable', 'email'],
            'phone' => ['required_without:email', 'nullable', 'string', 'digits:10'],
            'password' => ['required', 'string'],
            'client' => ['required', Rule::enum(LoginClient::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
            'password' => 'contraseña',
            'client' => 'cliente',
        ];
    }

    public function toData(): LoginData
    {
        return LoginData::from($this->validated());
    }
}
