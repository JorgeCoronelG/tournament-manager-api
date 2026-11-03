<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Team;

/**
 * Veto de un equipo: baja automática en los torneos donde está inscrito.
 */
interface TeamBanServiceInterface
{
    /**
     * Torneos que afectaría el veto, sin cambiar nada: por cada inscripción activa en un torneo en planeación (action
     * removed) o en curso o en liguilla (action withdrawn), con los partidos pendientes del equipo y los jugadores que
     * se liberarían. Los torneos finalizados no se tocan y no aparecen.
     *
     * @return array{tournaments: list<array{tournament_id: int, name: string, status: string, action: string, pending_matches: int, players_released: int}>, totals: array{removed_tournaments: int, withdrawn_tournaments: int, pending_matches: int, players_released: int}}
     */
    public function preview(Team $team): array;

    /**
     * Veta al equipo con su motivo y, en una sola transacción, quita sus inscripciones activas de los torneos en
     * planeación y da de baja las de los torneos en curso o en liguilla (mismo servicio de baja, con el motivo
     * «Equipo vetado: …» y quien veta). Los torneos finalizados y las inscripciones ya dadas de baja no se tocan.
     * Si el equipo ya estaba vetado no repite nada.
     *
     * @return array{team: Team, effects: array{removed_tournaments: int, withdrawn_tournaments: int, pending_matches: int, players_released: int}}
     *
     * @throws CustomErrorException
     */
    public function ban(Team $team, string $reason, int $userId): array;
}
