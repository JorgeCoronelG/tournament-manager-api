<?php

namespace App\Core\Enum;

enum RosterStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case REMOVED = 'removed';
    case RELEASED = 'released';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::ACTIVE => 'Activo',
            self::REJECTED => 'Rechazado',
            self::CANCELLED => 'Cancelado',
            self::REMOVED => 'Removido',
            self::RELEASED => 'Liberado',
        };
    }

    /**
     * Si la fila ocupa un lugar y un dorsal del equipo (cuenta para el máximo de la plantilla).
     */
    public function isOpen(): bool
    {
        return $this === self::PENDING || $this === self::ACTIVE;
    }

    /**
     * Resultado del escaneo del jugador con esta fila: solo un roster activo es válido; pending, rejected y
     * cancelled cuentan como no inscrito y removed conserva su propio resultado.
     */
    public function scanResult(): ScanResult
    {
        return match ($this) {
            self::ACTIVE => ScanResult::VALID,
            self::REMOVED => ScanResult::REMOVED,
            self::PENDING, self::REJECTED, self::CANCELLED, self::RELEASED => ScanResult::NOT_REGISTERED,
        };
    }
}
