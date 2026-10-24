<?php

namespace App\Http\Requests\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Helpers\Names;
use App\Models\PlayoffSource;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta (POST) y edición (PATCH) de grupos. El torneo sale de la ruta. En el alta `type` elige entre grupo regular y
 * liguilla; en la edición el tipo es el del grupo y no se puede cambiar. Las reglas de negocio de las fuentes
 * (rangos, traslapes, potencia de 2) las valida GroupService.
 */
class SaveGroupRequest extends FormRequest
{
    private const MAX_SMALLINT = 65535;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * En las fuentes de mejores de una posición la posición final es la inicial.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->input('sources');

        if (! is_array($input)) {
            return;
        }

        $sources = [];

        foreach ($input as $index => $source) {
            if (is_array($source) && ($source['kind'] ?? null) === PlayoffSource::KIND_BEST_OF_POSITION && isset($source['position_start'])) {
                $source['position_end'] = $source['position_start'];
            }

            $sources[$index] = $source;
        }

        $this->merge(['sources' => $sources]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->route('group');
        $editing = $group instanceof TournamentGroup;

        $rules = [
            'name' => [$editing ? 'sometimes' : 'required', 'string', 'max:100', $this->uniqueNameInTournament()],
        ];

        if (! $editing) {
            $rules['type'] = ['sometimes', Rule::enum(TournamentGroupType::class)];
        }

        if ($this->isPlayoff()) {
            $rules += [
                'start_date' => ['nullable', 'date_format:Y-m-d'],
                'round_dates' => ['nullable', 'array'],
                'round_legs' => ['nullable', 'array'],
                'round_dates.*' => ['required', 'date_format:Y-m-d'],
                'round_interval_days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_SMALLINT],
                'sources' => [$editing ? 'sometimes' : 'required', 'array', 'min:1'],
                'sources.*.kind' => ['sometimes', Rule::in([PlayoffSource::KIND_RANGE, PlayoffSource::KIND_BEST_OF_POSITION])],
                'sources.*.source_group_id' => ['required_unless:sources.*.kind,best_of_position', 'nullable', 'integer', 'min:1'],
                'sources.*.best_count' => ['required_if:sources.*.kind,best_of_position', 'nullable', 'integer', 'max:'.self::MAX_SMALLINT],
                'sources.*.position_start' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SMALLINT],
                'sources.*.position_end' => ['required', 'integer', 'gte:sources.*.position_start', 'max:'.self::MAX_SMALLINT],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'type' => 'tipo',
            'start_date' => 'fecha de inicio',
            'round_dates' => 'fechas de las rondas',
            'round_dates.*' => 'fecha del partido',
            'round_legs' => 'partidos por ronda',
            'round_interval_days' => 'días entre rondas',
            'sources' => 'fuentes',
            'sources.*.kind' => 'tipo de fuente',
            'sources.*.source_group_id' => 'grupo de origen',
            'sources.*.best_count' => 'cantidad de mejores',
            'sources.*.position_start' => 'posición inicial',
            'sources.*.position_end' => 'posición final',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToSave(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }

    private function isPlayoff(): bool
    {
        $group = $this->route('group');

        $type = $group instanceof TournamentGroup ? $group->type->value : $this->input('type');

        return $type === TournamentGroupType::PLAYOFF->value;
    }

    /**
     * Único dentro del torneo sin distinguir mayúsculas, acentos ni espacios extra (grupos regulares y liguillas).
     */
    private function uniqueNameInTournament(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $tournament = $this->route('tournament');
            $current = $this->route('group');

            if (! is_string($value) || ! $tournament instanceof Tournament) {
                return;
            }

            $taken = Names::anyMatches(
                TournamentGroup::query()
                    ->where('tournament_id', $tournament->id)
                    ->when($current instanceof TournamentGroup, fn ($query) => $query->where('id', '!=', $current instanceof TournamentGroup ? $current->id : 0))
                    ->pluck('name'),
                $value
            );

            if ($taken) {
                $fail(Message::GROUP_NAME_TAKEN);
            }
        };
    }
}
