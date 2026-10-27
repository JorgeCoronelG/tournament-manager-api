<?php

namespace App\Http\Requests\Matches;

use Illuminate\Foundation\Http\FormRequest;

class SwapMatchScheduleRequest extends FormRequest
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
        return ['with_match_id' => ['required', 'integer', 'min:1']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['with_match_id' => 'partido a intercambiar'];
    }

    public function withMatchId(): int
    {
        return $this->integer('with_match_id');
    }
}
