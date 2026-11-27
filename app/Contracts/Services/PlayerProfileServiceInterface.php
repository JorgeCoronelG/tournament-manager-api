<?php

namespace App\Contracts\Services;

use App\Models\User;

/**
 * App móvil, rol Jugador: lo que necesita la pestaña "Mi QR" (su código, sus equipos y sus próximos partidos).
 */
interface PlayerProfileServiceInterface
{
    /** Cuántos próximos partidos se mandan. */
    public const UPCOMING_LIMIT = 5;

    /**
     * Perfil del jugador con sesión. 404 si el usuario todavía no tiene ficha de jugador.
     *
     * @return array{player_id: int, qr_code: string, teams: list<array<string, mixed>>, upcoming_matches: list<array<string, mixed>>}
     */
    public function profileOf(User $user): array;
}
