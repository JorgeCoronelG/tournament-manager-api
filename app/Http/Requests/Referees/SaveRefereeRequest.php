<?php

namespace App\Http\Requests\Referees;

use App\Core\Enum\Message;
use App\Data\Referees\RefereeData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de árbitros comparten reglas; en edición se ignora al propio usuario ({user}).
 * No hay campo de rol: el servicio siempre asigna referee.
 */
class SaveRefereeRequest extends FormRequest
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
        $ignore = $this->route('user');

        return [
            'first_name' => ['required', 'string', 'min:3', 'max:100'],
            'last_name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', Rule::unique('users')->whereNull('deleted_at')->ignore($ignore)],
            'phone' => ['nullable', 'string', 'digits:10', Rule::unique('users')->whereNull('deleted_at')->ignore($ignore)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => Message::REFEREE_DUPLICATE_USER,
            'phone.unique' => Message::REFEREE_DUPLICATE_USER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellido',
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
        ];
    }

    public function toData(): RefereeData
    {
        return RefereeData::from($this->validated());
    }
}
