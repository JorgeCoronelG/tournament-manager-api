<?php

namespace App\Contracts\Services;

use Illuminate\Http\UploadedFile;

/**
 * Procesa la imagen subida de un logo. Es una interfaz aparte para poder simularla en tests.
 */
interface LogoProcessorInterface
{
    /**
     * Devuelve el contenido binario del logo ya procesado, en WebP.
     */
    public function process(UploadedFile $file): string;
}
