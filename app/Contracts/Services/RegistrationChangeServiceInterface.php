<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Registration;
use App\Models\Tournament;

/**
 * Baja y reemplazo de un equipo ya inscrito, con el torneo en curso o en liguilla (baja) o en curso (reemplazo).
 */
interface RegistrationChangeServiceInterface
{
    /**
     * Qué pasaría al dar de baja a la inscripción, sin cambiar nada: partidos jugados que se conservan, cantidad de
     * partidos pendientes (informativa), jugadores que se liberan y avisos.
     *
     * @return array{kept_played: int, pending_matches: int, players_released: int, warnings: list<string>}
     *
     * @throws CustomErrorException
     */
    public function withdrawPreview(Tournament $tournament, Registration $registration): array;

    /**
     * Da de baja a la inscripción: solo la marca withdrawn y libera a sus jugadores (released). No toca los partidos:
     * los pendientes conservan horario, cancha y árbitro, y se resuelven con victoria por default o con un reemplazo.
     *
     * @return array{kept_played: int, pending_matches: int, players_released: int, registration: Registration}
     *
     * @throws CustomErrorException
     */
    public function withdraw(Tournament $tournament, Registration $registration, string $reason, int $userId): array;

    /**
     * Historial de equipos de la inscripción (reemplazos), del más reciente al más antiguo.
     *
     * @return list<array{id: int, old_team: array{id: int, name: string}, new_team: array{id: int, name: string}, reason: string, changed_by: array{id: int, name: string}|null, created_at: string|null}>
     */
    public function teamChanges(Registration $registration): array;

    /**
     * Cambia el equipo de una inscripción activa o dada de baja (misma fila: conserva grupo, partidos con su horario y
     * cancha, puntos y estadísticas) y la reactiva. Solo con el torneo en curso; el plantel anterior queda released y
     * el nuevo empieza vacío.
     *
     * @return array{registration: Registration, warnings: list<string>}
     *
     * @throws CustomErrorException
     */
    public function replace(Tournament $tournament, Registration $registration, int $teamId, string $reason, int $userId): array;
}
