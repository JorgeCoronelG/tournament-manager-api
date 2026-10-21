<?php

namespace App\Http\Requests\Tournaments;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Data\Tournaments\TournamentData;
use App\Helpers\Names;
use App\Models\League;
use App\Models\Tournament;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de torneos comparten las mismas reglas. `status` no se acepta (queda
 * fuera de validated()) y la liga sale de la ruta, nunca del body.
 */
class SaveTournamentRequest extends FormRequest
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
        $ageRules = ['nullable', 'integer', 'between:'.Tournament::MIN_PLAYER_AGE.','.Tournament::MAX_PLAYER_AGE];

        return [
            'name' => ['required', 'string', 'max:150', $this->uniqueNameInLeague()],
            'gender' => ['required', Rule::enum(TournamentGender::class)],
            'min_age' => [...$ageRules, $this->notGreaterThanMaxAge()],
            'max_age' => $ageRules,
            'max_roster_size' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'min_matches_for_playoffs' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'start_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'gender' => 'género',
            'min_age' => 'edad mínima',
            'max_age' => 'edad máxima',
            'max_roster_size' => 'máximo de jugadores por equipo',
            'min_matches_for_playoffs' => 'partidos mínimos para la liguilla',
            'start_date' => 'fecha de inicio',
        ];
    }

    public function toData(): TournamentData
    {
        return TournamentData::from($this->validated());
    }

    /**
     * Único dentro de la liga y del género entre torneos no eliminados ni finalizados, sin
     * distinguir mayúsculas, acentos ni espacios extra. Un mismo nombre puede existir en
     * varonil y femenil.
     */
    private function uniqueNameInLeague(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $league = $this->route('league');
            $current = $this->route('tournament');
            $ignoreId = $current instanceof Tournament ? $current->id : null;

            $gender = TournamentGender::tryFrom($this->string('gender')->toString());

            if (! is_string($value) || ! $league instanceof League || $gender === null) {
                return;
            }

            $exists = Names::anyMatches(
                Tournament::query()
                    ->where('league_id', $league->id)
                    ->where('gender', $gender)
                    ->where('status', '!=', TournamentStatus::FINISHED)
                    ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                    ->pluck('name'),
                $value
            );

            if ($exists) {
                $fail(Message::TOURNAMENT_NAME_TAKEN);
            }
        };
    }

    private function notGreaterThanMaxAge(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $max = $this->input('max_age');

            if (is_numeric($value) && is_numeric($max) && (int) $value > (int) $max) {
                $fail('La :attribute no puede ser mayor que la edad máxima.');
            }
        };
    }
}
