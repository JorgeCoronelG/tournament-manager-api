<?php

namespace App\Http\Requests\Users;

use App\Core\Enum\Role as RoleEnum;
use App\Data\Users\CreateUserData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
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
        $roles = $this->input('roles', []);
        $phoneRequired = is_array($roles) && in_array(RoleEnum::PLAYER->value, $roles, false);

        return [
            'first_name' => ['required', 'string', 'min:3', 'max:100'],
            'last_name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', Rule::unique('users')->whereNull('deleted_at')],
            'phone' => [
                $phoneRequired ? 'required' : 'nullable',
                'string',
                'digits:10',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'integer',
                Rule::exists('roles', 'id')->whereNull('deleted_at'),
                Rule::notIn([RoleEnum::SUPERADMIN->value]),
            ],
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
        ];
    }

    public function toData(): CreateUserData
    {
        return CreateUserData::from($this->validated());
    }
}
