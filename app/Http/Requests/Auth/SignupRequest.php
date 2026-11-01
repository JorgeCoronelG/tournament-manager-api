<?php

namespace App\Http\Requests\Auth;

use App\Core\Enum\LoginClient;
use App\Core\Enum\PlayerGender;
use App\Data\Auth\SignupData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registro propio desde la app móvil (multipart/form-data). Siempre crea un jugador.
 */
class SignupRequest extends FormRequest
{
    public const MIN_AGE = 18;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adultBy = CarbonImmutable::today()->subYears(self::MIN_AGE)->toDateString();

        return [
            'first_name' => ['required', 'string', 'min:3', 'max:100'],
            'last_name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'digits:10', Rule::unique('users')->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$adultBy],
            'gender' => ['required', Rule::enum(PlayerGender::class)],
            // Selfie de la cámara frontal. La app ya revisa que haya un rostro; aquí se valida que sea una imagen
            // real (por contenido, no por extensión) y de tamaño suficiente para reconocer la cara.
            'photo' => [
                'required', 'file', 'image',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240', 'dimensions:min_width=300,min_height=300',
            ],
            'client' => ['required', Rule::in([LoginClient::MOBILE->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'Debes tener al menos '.self::MIN_AGE.' años para registrarte.',
            'email.unique' => 'Ya existe una cuenta con este correo.',
            'phone.unique' => 'Ya existe una cuenta con este teléfono.',
            'photo.required' => 'La foto es obligatoria para completar el registro.',
            'photo.dimensions' => 'La foto es muy pequeña. Tómala de nuevo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellidos',
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
            'password' => 'contraseña',
            'birth_date' => 'fecha de nacimiento',
            'gender' => 'rama',
            'photo' => 'foto',
            'client' => 'cliente',
        ];
    }

    public function toData(): SignupData
    {
        /** @var UploadedFile $photo */
        $photo = $this->file('photo');

        return new SignupData(
            first_name: trim($this->string('first_name')->toString()),
            last_name: trim($this->string('last_name')->toString()),
            email: mb_strtolower(trim($this->string('email')->toString())),
            phone: $this->string('phone')->toString(),
            password: $this->string('password')->toString(),
            birth_date: CarbonImmutable::createFromFormat('Y-m-d', $this->string('birth_date')->toString())->startOfDay(),
            gender: PlayerGender::from($this->string('gender')->toString()),
            photo: $photo,
        );
    }
}
