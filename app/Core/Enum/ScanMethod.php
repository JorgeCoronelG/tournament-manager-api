<?php

namespace App\Core\Enum;

/**
 * Cómo validó el árbitro al jugador: escaneando su QR o buscándolo por nombre y comparando la foto.
 */
enum ScanMethod: string
{
    case QR = 'qr';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::QR => 'Código QR',
            self::MANUAL => 'Sin QR (nombre y foto)',
        };
    }
}
