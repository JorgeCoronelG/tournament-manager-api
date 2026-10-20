<?php

namespace App\Http\Requests\Teams;

use App\Data\Teams\ManagerInputData;

/**
 * Asigna o cambia el delegado: acepta las mismas dos formas que el alta del equipo, pero
 * aquí una de ellas es obligatoria.
 */
class AssignTeamManagerRequest extends ManagerInputRequest
{
    protected function managerRequired(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->managerRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->managerMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->managerAttributes();
    }

    public function toInput(): ManagerInputData
    {
        return $this->managerInput() ?? new ManagerInputData;
    }
}
