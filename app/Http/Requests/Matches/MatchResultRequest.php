<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcador de un partido de fase regular desde el panel.
 */
class MatchResultRequest extends FormRequest
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
            'home_score' => ['required', 'integer', 'min:0', 'max:99'],
            'away_score' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'home_score' => 'marcador local',
            'away_score' => 'marcador visitante',
        ];
    }
}
