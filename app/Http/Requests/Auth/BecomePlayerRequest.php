<?php

namespace App\Http\Requests\Auth;

use App\Core\Enum\PlayerGender;
use App\Data\Auth\BecomePlayerData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Delegado o árbitro que también quiere jugar: completa en la app los datos de jugador (multipart/form-data).
 * El teléfono solo es obligatorio si su cuenta no lo tiene.
 */
class BecomePlayerRequest extends FormRequest
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
        $adultBy = CarbonImmutable::today()->subYears(SignupRequest::MIN_AGE)->toDateString();
        $user = $this->user();
        $userId = $user instanceof User ? $user->id : null;
        $hasPhone = $user instanceof User && is_string($user->phone) && $user->phone !== '';

        return [
            'phone' => [
                $hasPhone ? 'nullable' : 'required', 'string', 'digits:10',
                Rule::unique('users')->whereNull('deleted_at')->ignore($userId),
            ],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$adultBy],
            'gender' => ['required', Rule::enum(PlayerGender::class)],
            'photo' => [
                'required', 'file', 'image',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240', 'dimensions:min_width=300,min_height=300',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'Debes tener al menos '.SignupRequest::MIN_AGE.' años para jugar.',
            'phone.unique' => 'Ya existe una cuenta con este teléfono.',
            'photo.required' => 'La foto es obligatoria para tener tu QR de jugador.',
            'photo.dimensions' => 'La foto es muy pequeña. Tómala de nuevo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone' => 'teléfono',
            'birth_date' => 'fecha de nacimiento',
            'gender' => 'rama',
            'photo' => 'foto',
        ];
    }

    public function toData(): BecomePlayerData
    {
        /** @var UploadedFile $photo */
        $photo = $this->file('photo');
        $phone = $this->string('phone')->toString();

        return new BecomePlayerData(
            phone: $phone === '' ? null : $phone,
            birth_date: CarbonImmutable::parse($this->string('birth_date')->toString())->startOfDay(),
            gender: PlayerGender::from($this->string('gender')->toString()),
            photo: $photo,
        );
    }
}
