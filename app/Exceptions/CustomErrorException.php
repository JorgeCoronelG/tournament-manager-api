<?php

namespace App\Exceptions;

use Exception;

class CustomErrorException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $payload  Si viene, sustituye al mensaje como `error` de la respuesta (p. ej. {blockers: [...]}).
     */
    public function __construct(string $message = '', int $code = 0, public readonly ?array $payload = null)
    {
        parent::__construct($message, $code);
    }
}
