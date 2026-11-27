<?php

namespace App\Contracts\Services;

use Illuminate\Http\UploadedFile;

/**
 * Procesa la selfie del registro. Es una interfaz aparte para poder simularla en tests.
 */
interface PhotoProcessorInterface
{
    /**
     * Devuelve el contenido binario de la foto ya procesada, en WebP.
     */
    public function process(UploadedFile $file): string;
}
