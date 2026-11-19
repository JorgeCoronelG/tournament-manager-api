<?php

namespace App\Http\Requests\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Data\Leagues\LeagueData;
use App\Helpers\Names;
use App\Models\League;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de ligas comparten las mismas reglas; en edición se
 * ignora la propia liga al validar la unicidad del nombre.
 */
class SaveLeagueRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150', $this->uniqueName()],
            'admin_user_id' => ['required', 'integer', $this->validAdmin()],
            // `present`: el campo debe venir, pero null significa «sin límite».
            'max_active_tournaments' => ['present', 'nullable', 'integer', 'min:1', 'max:65535'],
            // Reservado para los planes futuros: hoy no se configura.
            'max_teams_per_tournament' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'admin_user_id' => 'encargado',
            'max_active_tournaments' => 'máximo de torneos activos',
            'max_teams_per_tournament' => 'máximo de equipos por torneo',
        ];
    }

    public function toData(): LeagueData
    {
        return LeagueData::from($this->validated());
    }

    /**
     * Único entre las ligas no eliminadas del mismo encargado, sin distinguir
     * mayúsculas, acentos ni espacios extra. Encargados distintos pueden repetir nombre.
     */
    private function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $adminId = $this->input('admin_user_id');

            if ($adminId === null || $adminId === '') {
                return;
            }

            $exists = Names::anyMatches(
                League::query()
                    ->where('admin_user_id', $adminId)
                    ->when($this->route('id') !== null, fn ($query) => $query->where('id', '!=', $this->route('id')))
                    ->pluck('name'),
                $value
            );

            if ($exists) {
                $fail('El :attribute ya está en uso.');
            }
        };
    }

    /**
     * Usuario existente, no eliminado, activo y con rol league_admin. Una cuenta
     * pendiente (is_active = true, aún sin activar) sí es válida.
     */
    private function validAdmin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $valid = User::query()
                ->whereKey($value)
                ->where('is_active', true)
                ->whereHas('roles', fn ($roles) => $roles->where('roles.id', RoleEnum::LEAGUE_ADMIN->value))
                ->exists();

            if (! $valid) {
                $fail('El :attribute debe ser un usuario activo con rol de administrador de liga.');
            }
        };
    }
}
