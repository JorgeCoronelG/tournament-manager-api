<?php

namespace App\Core\Enum;

enum RosterStatus: string
{
    case ACTIVE = 'active';
    case REMOVED = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::REMOVED => 'Removido',
        };
    }
}
