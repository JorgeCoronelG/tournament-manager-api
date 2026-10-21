<?php

namespace App\Http\Requests\Registrations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La liga y el torneo salen de la ruta; el body solo trae los equipos. Que cada equipo pueda inscribirse
 * lo valida RegistrationService, que conoce el torneo.
 */
class StoreRegistrationRequest extends FormRequest
{
    private const MAX_TEAMS = 100;

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
            'team_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_TEAMS],
            'team_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'team_ids' => 'equipos',
            'team_ids.*' => 'equipo',
        ];
    }

    /**
     * @return list<int>
     */
    public function teamIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('team_ids');

        return array_map('intval', $ids);
    }
}
