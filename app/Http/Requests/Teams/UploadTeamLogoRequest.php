<?php

namespace App\Http\Requests\Teams;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * `mimes` y `mimetypes` comprueban el contenido real del archivo, no la extensión que manda
 * el cliente; `image` además descarta SVG.
 */
class UploadTeamLogoRequest extends FormRequest
{
    public const MAX_KILOBYTES = 2048;

    public const MIN_DIMENSION = 64;

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
            'logo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::MAX_KILOBYTES,
                Rule::dimensions()->minWidth(self::MIN_DIMENSION)->minHeight(self::MIN_DIMENSION),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'logo' => 'logo',
        ];
    }

    public function logo(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('logo');
    }
}
