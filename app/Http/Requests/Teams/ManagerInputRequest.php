<?php

namespace App\Http\Requests\Teams;

use App\Core\Enum\Message;
use App\Data\Teams\ManagerInputData;
use App\Data\Teams\ManagerProfileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base de los requests que reciben un delegado en una de sus dos formas: `manager_user_code`
 * (existente) o `manager` (datos de uno nuevo). Si vienen ambas, 422.
 */
abstract class ManagerInputRequest extends FormRequest
{
    /**
     * Si el delegado es obligatorio (asignar) o puede omitirse (alta del equipo).
     */
    abstract protected function managerRequired(): bool;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function managerRules(): array
    {
        $required = $this->managerRequired();

        return [
            'manager_user_code' => [
                $required ? 'required_without:manager' : 'nullable',
                'string',
                'max:20',
                'prohibits:manager',
            ],
            'manager' => [$required ? 'required_without:manager_user_code' : 'nullable', 'array'],
            'manager.first_name' => ['required_with:manager', 'string', 'min:3', 'max:100'],
            'manager.last_name' => ['required_with:manager', 'string', 'min:3', 'max:100'],
            'manager.email' => [
                'required_with:manager',
                'email',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'manager.phone' => [
                'nullable',
                'string',
                'digits:10',
                Rule::unique('users', 'phone')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function managerMessages(): array
    {
        return [
            'manager_user_code.prohibits' => Message::TEAM_MANAGER_BOTH_FORMS,
            'manager_user_code.required_without' => Message::TEAM_MANAGER_REQUIRED,
            'manager.required_without' => Message::TEAM_MANAGER_REQUIRED,
            'manager.email.unique' => Message::TEAM_MANAGER_DUPLICATE_USER,
            'manager.phone.unique' => Message::TEAM_MANAGER_DUPLICATE_USER,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function managerAttributes(): array
    {
        return [
            'manager_user_code' => 'clave del delegado',
            'manager' => 'delegado',
            'manager.first_name' => 'nombre del delegado',
            'manager.last_name' => 'apellido del delegado',
            'manager.email' => 'correo electrónico del delegado',
            'manager.phone' => 'teléfono del delegado',
        ];
    }

    /**
     * Null si no se indicó delegado.
     */
    public function managerInput(): ?ManagerInputData
    {
        $code = $this->input('manager_user_code');

        if (is_string($code) && trim($code) !== '') {
            return new ManagerInputData(user_code: trim($code));
        }

        $manager = $this->validated('manager');

        if (! is_array($manager) || $manager === []) {
            return null;
        }

        return new ManagerInputData(profile: ManagerProfileData::from($manager));
    }
}
