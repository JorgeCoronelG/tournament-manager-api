<?php

namespace App\Contracts\Services;

use App\Models\Registration;
use App\Models\User;

/**
 * App móvil, rol Delegado: números de su equipo en el torneo para "Mi equipo".
 */
interface ManagerTeamSummaryServiceInterface
{
    /**
     * Goles y tarjetas por jugador (solo partidos jugados y eventos no anulados), suspensiones y vetos vigentes,
     * y los partidos del equipo con su marcador. 403 si el usuario no es el delegado del equipo.
     *
     * @return array{players: list<array<string, mixed>>, matches: list<array<string, mixed>>}
     */
    public function summaryFor(Registration $registration, User $manager): array;
}
