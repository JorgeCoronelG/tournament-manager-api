<?php

namespace App\Http\Requests\Schedule;

use App\Contracts\Services\ScheduleGeneratorServiceInterface as Generator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros del generador de horarios: modo de reparto, alcance y semilla opcional del modo aleatorio.
 */
class GenerateScheduleRequest extends FormRequest
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
            'mode' => ['nullable', Rule::in([Generator::MODE_BALANCED, Generator::MODE_RANDOM])],
            'scope' => ['nullable', Rule::in([Generator::SCOPE_ALL, Generator::SCOPE_UNSCHEDULED])],
            'seed' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'phase' => ['nullable', Rule::in(['regular', 'playoffs'])],
            'align_finals' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mode' => 'modo de reparto',
            'scope' => 'alcance',
            'seed' => 'semilla',
            'phase' => 'fase',
            'align_finals' => 'alinear finales',
        ];
    }

    public function mode(): string
    {
        return $this->filled('mode') ? $this->string('mode')->value() : Generator::MODE_BALANCED;
    }

    public function scope(): string
    {
        return $this->filled('scope') ? $this->string('scope')->value() : Generator::SCOPE_ALL;
    }

    public function seed(): ?int
    {
        return $this->filled('seed') ? $this->integer('seed') : null;
    }

    /**
     * "regular" (por defecto) o "playoffs".
     */
    public function phase(): string
    {
        return $this->filled('phase') ? $this->string('phase')->value() : 'regular';
    }

    public function alignFinals(): bool
    {
        return $this->filled('align_finals') ? $this->boolean('align_finals') : true;
    }
}
