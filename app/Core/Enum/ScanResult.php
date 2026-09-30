<?php

namespace App\Core\Enum;

enum ScanResult: string
{
    case VALID = 'valid';
    case NOT_REGISTERED = 'not_registered';
    case REMOVED = 'removed';
    case UNKNOWN_QR = 'unknown_qr';
    case BANNED = 'banned';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Válido',
            self::NOT_REGISTERED => 'No registrado',
            self::REMOVED => 'Removido',
            self::UNKNOWN_QR => 'QR desconocido',
            self::BANNED => 'Vetado',
            self::SUSPENDED => 'Suspendido',
        };
    }
}
