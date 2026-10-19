<?php

namespace App\Http\Requests\Fields;

use App\Core\Enum\Message;
use App\Data\Fields\FieldData;
use App\Models\Field;
use App\Models\League;
use App\Core\Enum\FieldStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de canchas comparten las mismas reglas. `status` solo se acepta en el alta
 * (opcional, por defecto activa); al editar queda fuera de validated() y se cambia con su
 * propio PATCH. La liga sale de la ruta, nunca del body.
 */
class SaveFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'name' => ['required', 'string', 'max:150', $this->uniqueNameInLeague()],
        ];

        if ($this->isMethod('POST')) {
            $rules['status'] = ['sometimes', Rule::enum(FieldStatus::class)];
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
            'status' => 'estado',
        ];
    }

    public function toData(): FieldData
    {
        return FieldData::from($this->validated());
    }

    /**
     * Único en la liga entre canchas no eliminadas (el scope de SoftDeletes ya excluye las
     * eliminadas), sin distinguir mayúsculas ni espacios en los extremos.
     */
    private function uniqueNameInLeague(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $league = $this->route('league');
            $current = $this->route('field');
            $ignoreId = $current instanceof Field ? $current->id : null;

            if (! is_string($value) || ! $league instanceof League) {
                return;
            }

            $exists = Field::query()
                ->where('league_id', $league->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($value))])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                $fail(Message::FIELD_NAME_TAKEN);
            }
        };
    }
}
