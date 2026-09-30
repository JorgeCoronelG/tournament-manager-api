<?php

namespace App\Http\Requests\Users;

use App\Core\Enum\Role as RoleEnum;
use App\Data\Users\UpdateUserData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $id = $this->route('id');
        $roles = $this->input('roles', []);
        $phoneRequired = is_array($roles) && in_array(RoleEnum::PLAYER->value, $roles, false);

        return [
            'first_name' => ['required', 'string', 'min:3', 'max:100'],
            'last_name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', Rule::unique('users')->whereNull('deleted_at')->ignore($id)],
            'phone' => [
                $phoneRequired ? 'required' : 'nullable',
                'string',
                'digits:10',
                Rule::unique('users')->whereNull('deleted_at')->ignore($id),
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'integer',
                Rule::exists('roles', 'id')->whereNull('deleted_at'),
                Rule::notIn([RoleEnum::SUPERADMIN->value]),
            ],
            'is_active' => ['required', 'boolean'],
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
            'roles' => 'roles',
            'roles.*' => 'rol',
            'is_active' => 'estado activo',
        ];
    }

    public function toData(): UpdateUserData
    {
        return UpdateUserData::from($this->validated());
    }
}
