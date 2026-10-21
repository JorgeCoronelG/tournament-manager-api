<?php

namespace App\Http\Requests\Players;

use App\Core\Enum\PlayerGender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlayerGenderRequest extends FormRequest
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
            'gender' => ['required', Rule::enum(PlayerGender::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['gender' => 'rama'];
    }

    public function gender(): PlayerGender
    {
        return PlayerGender::from($this->string('gender')->toString());
    }
}
