<?php

namespace App\Http\Requests\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Data\Leagues\LeagueData;
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
        ];
    }

    public function toData(): LeagueData
    {
        return LeagueData::from($this->validated());
    }

    /**
     * Único entre ligas no eliminadas, sin distinguir mayúsculas ni espacios en los extremos.
     */
    private function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $exists = League::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($value))])
                ->when($this->route('id') !== null, fn ($query) => $query->where('id', '!=', $this->route('id')))
                ->exists();

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
