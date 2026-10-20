<?php

namespace App\Services;

use App\Contracts\Services\LogoProcessorInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;

class LogoImageProcessor implements LogoProcessorInterface
{
    public const MAX_SIZE = 512;

    public const QUALITY = 80;

    /**
     * Gestor de imágenes nativo de Laravel (`Illuminate\Image`, con Intervention Image 4 y GD).
     * Reduce a un máximo de 512x512 conservando la proporción (scale() no amplía imágenes
     * pequeñas) y re-codifica en WebP: al re-codificar se eliminan los metadatos y cualquier
     * contenido incrustado en el archivo original.
     */
    public function process(UploadedFile $file): string
    {
        return Image::fromUpload($file)
            ->orient()
            ->scale(self::MAX_SIZE, self::MAX_SIZE)
            ->optimize('webp', self::QUALITY)
            ->toBytes();
    }
}
