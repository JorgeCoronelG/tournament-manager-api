<?php

namespace App\Contracts\Services;

use App\Data\Auth\SignupData;
use App\Models\User;

interface SignupServiceInterface
{
    /**
     * Crea la cuenta de un jugador desde la app (usuario, rol jugador, perfil de jugador con QR y selfie)
     * y devuelve la sesión iniciada.
     *
     * @return array{user: User, token: string}
     */
    public function register(SignupData $data): array;
}
