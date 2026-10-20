<?php

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

/**
 * Datos personales de un delegado nuevo (o de uno pendiente que se edita).
 */
class ManagerProfileData extends Data
{
    public function __construct(
        public string $first_name,
        public string $last_name,
        public string $email,
        public ?string $phone = null,
    ) {}
}
