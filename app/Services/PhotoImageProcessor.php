<?php

namespace App\Services;

use App\Contracts\Services\PhotoProcessorInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;

class PhotoImageProcessor implements PhotoProcessorInterface
{
    public const MAX_SIZE = 800;

    public const QUALITY = 80;

    /**
     * Igual que el logo del equipo: corrige la orientación de la cámara, reduce a un máximo de 800x800
     * conservando la proporción (sin ampliar) y re-codifica en WebP, lo que quita los metadatos (GPS incluido).
     * 800 px basta para que el árbitro compare la cara y deja cada foto en unas decenas de KB.
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
