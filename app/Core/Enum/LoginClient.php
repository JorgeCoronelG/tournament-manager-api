<?php

namespace App\Core\Enum;

/**
 * Cliente desde el que se inicia sesión.
 */
enum LoginClient: string
{
    case WEB = 'web';
    case MOBILE = 'mobile';

    /**
     * Mensaje cuando el usuario no tiene ningún rol permitido en este cliente.
     */
    public function deniedMessage(): string
    {
        return match ($this) {
            self::WEB => Message::ACCESS_ONLY_ADMINS,
            self::MOBILE => Message::ACCESS_ONLY_MOBILE_USERS,
        };
    }
}
