<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generación del calendario de la fase regular. `shuffle` (default true) sortea el orden de los equipos antes de rotar.
 */
class GenerateMatchesRequest extends FormRequest
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
            'shuffle' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shuffle' => 'sorteo',
        ];
    }

    public function shuffle(): bool
    {
        return $this->boolean('shuffle', true);
    }
}
