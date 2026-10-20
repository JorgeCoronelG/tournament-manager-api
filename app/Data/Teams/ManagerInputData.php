<?php

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

/**
 * Cómo se indica el delegado de un equipo: un delegado existente por su clave, o los datos
 * de uno nuevo. Nunca ambos (lo valida el request).
 */
class ManagerInputData extends Data
{
    public function __construct(
        public ?string $user_code = null,
        public ?ManagerProfileData $profile = null,
    ) {}
}
