<?php

namespace App\Core\Enum;

enum TeamStatus: string
{
    case ACTIVE = 'active';
    case BANNED = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::BANNED => 'Vetado',
        };
    }
}
