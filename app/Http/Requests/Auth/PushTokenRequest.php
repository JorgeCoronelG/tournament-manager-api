<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PushTokenRequest extends FormRequest
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
            // Formato de Expo: ExponentPushToken[...] (o ExpoPushToken[...]).
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[.+\]$/'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'token' => 'token de notificaciones',
            'platform' => 'plataforma',
        ];
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }

    public function platform(): ?string
    {
        $platform = $this->input('platform');

        return is_string($platform) ? $platform : null;
    }
}
