<?php

namespace App\Contracts\Repositories;

use App\Core\Contracts\BaseRepositoryInterface;
use App\Models\Roster;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepositoryInterface<Roster>
 */
interface RosterRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Bloquea la fila hasta que termine la transacción.
     */
    public function lockById(int $rosterId): Roster;

    /**
     * Todas las filas del jugador en el torneo, bloqueadas hasta que termine la transacción.
     *
     * @return Collection<int, Roster>
     */
    public function lockByPlayerInTournament(int $playerId, int $tournamentId): Collection;

    /**
     * Filas abiertas (pending o active) del equipo, bloqueadas: cuentan para el máximo de la plantilla y los dorsales.
     *
     * @return Collection<int, Roster>
     */
    public function lockOpenByRegistration(int $registrationId): Collection;

    /**
     * Si el jugador tiene un veto activo (sin levantar) en la liga.
     */
    public function playerBannedInLeague(int $playerId, int $leagueId): bool;

    /**
     * Equipo con más jugadores abiertos (pending o active) del torneo: nombre y cantidad; null si no hay.
     *
     * @return array{team: string, players: int}|null
     */
    public function largestOpenRoster(int $tournamentId): ?array;

    public function createRequest(int $registrationId, int $tournamentId, int $playerId, int $jerseyNumber, int $requestedByUserId): Roster;

    public function accept(Roster $roster): Roster;

    public function reject(Roster $roster): Roster;

    public function remove(Roster $roster, int $removedByUserId, string $reason): Roster;

    public function authorizeRejoin(Roster $roster): Roster;

    /**
     * Pasa a cancelled las solicitudes pending del jugador en el torneo, salvo la indicada.
     */
    public function cancelPendingOfPlayer(int $playerId, int $tournamentId, int $exceptRosterId): int;

    /**
     * Pasa a cancelled todas las solicitudes pending del torneo (cierre de plantillas).
     */
    public function cancelPendingOfTournament(int $tournamentId): int;
}
