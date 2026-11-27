<?php

namespace App\Contracts\Services;

use App\Data\Auth\BecomePlayerData;
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

    /**
     * Un delegado o árbitro que también quiere jugar: guarda teléfono y selfie, crea su perfil de jugador con QR
     * y le agrega el rol jugador. 422 si ya tenía perfil de jugador.
     */
    public function becomePlayer(User $user, BecomePlayerData $data): User;
}
