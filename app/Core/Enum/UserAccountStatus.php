<?php

namespace App\Core\Enum;

enum UserAccountStatus: string
{
    case PENDING = 'pending';
    case INACTIVE = 'inactive';
    case ACTIVE = 'active';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::INACTIVE => 'Inactivo',
            self::ACTIVE => 'Activo',
        };
    }
}
