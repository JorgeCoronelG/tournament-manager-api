<?php

namespace App\Http\Requests\Teams;

use App\Core\Enum\Message;
use App\Data\Teams\ManagerProfileData;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de los datos de un delegado con cuenta pendiente; se ignora al propio delegado
 * en las reglas de unicidad.
 */
class UpdateTeamManagerRequest extends FormRequest
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
        $team = $this->route('team');
        $ignore = $team instanceof Team ? $team->manager_user_id : null;

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
            'email.unique' => Message::TEAM_MANAGER_DUPLICATE_USER,
            'phone.unique' => Message::TEAM_MANAGER_DUPLICATE_USER,
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

    public function toData(): ManagerProfileData
    {
        return ManagerProfileData::from($this->validated());
    }
}
