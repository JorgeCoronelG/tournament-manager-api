<?php

namespace App\Core\Enum;

enum RegistrationStatus: string
{
    case ACTIVE = 'active';
    case WITHDRAWN = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
            self::WITHDRAWN => 'Baja',
        };
    }
}
