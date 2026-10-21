<?php

namespace App\Http\Requests\Teams;

use App\Core\Enum\Sport;
use App\Data\Teams\CreateTeamData;
use App\Data\Teams\TeamData;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de equipos comparten el nombre. Solo el alta (POST) acepta delegado; en la
 * edición queda fuera de validated(). `status` y la liga nunca se toman del body. La unicidad del
 * nombre depende del delegado, así que la valida TeamService.
 */
class SaveTeamRequest extends ManagerInputRequest
{
    protected function managerRequired(): bool
    {
        return false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'sport' => ['sometimes', Rule::enum(Sport::class)],
        ];

        return $this->isMethod('POST') ? $rules + $this->managerRules() : $rules;
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
        return ['name' => 'nombre', 'sport' => 'deporte'] + $this->managerAttributes();
    }

    public function toData(): TeamData
    {
        return new TeamData($this->string('name')->toString(), $this->sport());
    }

    public function toCreateData(): CreateTeamData
    {
        return new CreateTeamData($this->string('name')->toString(), $this->managerInput(), $this->sport());
    }

    private function sport(): ?Sport
    {
        return Sport::tryFrom($this->string('sport')->toString());
    }
}
