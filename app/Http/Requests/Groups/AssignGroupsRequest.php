<?php

namespace App\Http\Requests\Groups;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lista de {registration_id, group_id}. Que cada inscripción sea activa del torneo y cada grupo regular del mismo
 * torneo lo valida GroupService, que conoce el torneo.
 */
class AssignGroupsRequest extends FormRequest
{
    private const MAX_ASSIGNMENTS = 500;

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
            'assignments' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSIGNMENTS],
            'assignments.*.registration_id' => ['required', 'integer', 'min:1', 'distinct'],
            'assignments.*.group_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'assignments' => 'asignaciones',
            'assignments.*.registration_id' => 'inscripción',
            'assignments.*.group_id' => 'grupo',
        ];
    }

    /**
     * @return list<array{registration_id: int, group_id: int|null}>
     */
    public function assignments(): array
    {
        /** @var list<array{registration_id: int|string, group_id: int|string|null}> $rows */
        $rows = $this->validated('assignments');

        return array_map(fn (array $row) => [
            'registration_id' => (int) $row['registration_id'],
            'group_id' => $row['group_id'] === null ? null : (int) $row['group_id'],
        ], $rows);
    }
}
